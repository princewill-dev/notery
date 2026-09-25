<?php

namespace App\Http\Controllers;

use App\Exceptions\UploadValidationException;
use App\Support\EncryptedFile;
use App\Support\EncryptedFileWriter;
use App\Models\PortalSession;
use App\Models\PortalMessage;
use App\Models\Save;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PortalController extends Controller
{
    private const MAX_CHUNK_SIZE = 6 * 1024 * 1024;
    private const ALLOWED_TYPES = ['image', 'pdf', 'mp4', 'zip'];
    private const ALLOWED_DURATIONS = [5, 10, 30, 60];
    private const IMAGE_MAX_SIZE = 20 * 1024; // 20 MB in KB
    private const LARGE_MAX_SIZE = 512000;    // 500 MB in KB
    private const PEER_IDLE_SECONDS = 90;     // release a slot after this long without a poll

    /** Hard cap on messages per portal to bound storage and poll payloads. */
    private const MAX_MESSAGES_PER_PORTAL = 200;

    /** Upper bound on files in a single portal upload session. */
    private const MAX_FILES_PER_UPLOAD = 20;

    public function create(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'duration' => 'required|integer|in:' . implode(',', self::ALLOWED_DURATIONS),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid duration',
            ], 422);
        }

        $duration = (int) $request->input('duration');

        try {
            $code = $this->generateUniqueCode();
        } catch (\RuntimeException $e) {
            Log::error('Portal code allocation failed', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'No portal codes are available right now. Please try again shortly.',
            ], 503);
        }

        $hashedCode = hash('sha256', $code);

        $peerId = Str::uuid()->toString();

        PortalSession::create([
            'code' => $hashedCode,
            'duration_minutes' => $duration,
            'expires_at' => now()->addMinutes($duration),
            'status' => 'active',
            // Register the creator's peer slot up front so it is reserved and
            // reused when they open /p/{code} (instead of being discarded).
            'peer_ids' => [$peerId],
        ]);

        $request->session()->put('portal_peer_' . $code, $peerId);
        $request->session()->save();

        Log::info('Portal created', ['duration' => $duration, 'session_id' => $request->session()->getId()]);

        return response()->json([
            'status' => 'ok',
            'code' => $code,
            'portal_url' => url('/p/' . $code),
            'peer_id' => $peerId,
            'expires_at' => now()->addMinutes($duration)->toIso8601String(),
        ]);
    }

    public function show(Request $request, $code)
    {
        if (!preg_match('/^\d{4}$/', $code)) {
            $errorMessage = 'Invalid portal code';
            return response()->view('portal.expired', compact('errorMessage'), 404);
        }

        $hashedCode = hash('sha256', $code);
        $session = PortalSession::where('code', $hashedCode)->first();

        if (!$session) {
            $errorMessage = 'This portal does not exist';
            return response()->view('portal.expired', compact('errorMessage'), 404);
        }

        if ($session->status === 'closed') {
            $errorMessage = 'This portal has been closed';
            return response()->view('portal.expired', compact('errorMessage'), 410);
        }

        if (now()->gt($session->expires_at)) {
            $errorMessage = 'This portal has expired';
            return response()->view('portal.expired', compact('errorMessage'), 410);
        }

        // Allocate the peer slot atomically. The previous read-then-write had
        // no lock, so concurrent shows overwrote each other's UUID (evicting a
        // peer), and slots were never released, so two requests could squat a
        // portal and lock out the intended partner.
        $peerId = DB::transaction(function () use ($request, $code) {
            $locked = PortalSession::where('code', hash('sha256', $code))
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                return null;
            }

            $peerIds = $locked->peer_ids ?? [];
            $seenAt = $locked->peer_seen_at ?? [];

            // Release slots for peers that have not polled recently.
            $cutoff = now()->subSeconds(self::PEER_IDLE_SECONDS)->getTimestamp();
            $peerIds = array_values(array_filter($peerIds, function ($id) use ($seenAt, $cutoff) {
                return isset($seenAt[$id]) && $seenAt[$id] >= $cutoff;
            }));

            $sessionPeerId = $request->session()->get('portal_peer_' . $code);

            if ($sessionPeerId && in_array($sessionPeerId, $peerIds, true)) {
                $peerId = $sessionPeerId;
            } elseif (count($peerIds) < 2) {
                $peerId = Str::uuid()->toString();
                $peerIds[] = $peerId;
                $request->session()->put('portal_peer_' . $code, $peerId);
            } else {
                return false; // portal is full
            }

            $seenAt[$peerId] = now()->getTimestamp();
            $locked->peer_ids = array_values($peerIds);
            $locked->peer_seen_at = $seenAt;
            $locked->save();

            return $peerId;
        });

        if ($peerId === null) {
            $errorMessage = 'This portal does not exist';
            return response()->view('portal.expired', compact('errorMessage'), 404);
        }

        if ($peerId === false) {
            $errorMessage = 'This portal is full (maximum 2 participants)';
            return response()->view('portal.expired', compact('errorMessage'), 409);
        }

        return view('portal', [
            'code' => $code,
            'peerId' => $peerId,
            'expiresAt' => $session->expires_at->toIso8601String(),
            'portalUrl' => url('/p/' . $code),
        ]);
    }

    public function poll(Request $request, $code)
    {
        $cursor = Validator::make($request->query(), [
            'last_id' => 'nullable|integer|min:0',
            'since' => 'nullable|integer|min:0',
        ]);

        if ($cursor->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid cursor',
            ], 422);
        }

        $hashedCode = hash('sha256', $code);
        $session = PortalSession::where('code', $hashedCode)->first();

        if (!$session) {
            return response()->json([
                'status' => 'error',
                'message' => 'Portal not found',
            ], 404);
        }

        $peerId = $this->getPeerId($request, $code);
        $this->touchPeer($session, $peerId);

        if (!$peerId || !in_array($peerId, $session->peer_ids ?? [])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Not a participant',
            ], 403);
        }

        if ($session->status === 'closed' || now()->gt($session->expires_at)) {
            $closedStatus = $session->status === 'closed' ? 'closed' : 'expired';
            $session->status = $closedStatus;
            $session->save();
            return response()->json([
                'status' => $closedStatus,
                'messages' => [],
                'peer_count' => count($session->peer_ids ?? []),
            ]);
        }

        $peerCount = count($session->peer_ids ?? []);

        // Cursor by monotonic message id. created_at only has second precision,
        // so a strict timestamp cursor silently dropped messages that shared a
        // second with the newest already-delivered message.
        $query = $session->messages()->orderBy('id', 'asc');
        $lastId = $request->query('last_id');

        if ($lastId !== null && $lastId !== '') {
            $query->where('id', '>', (int) $lastId);
        } else {
            // Backwards-compatible timestamp cursor for old clients.
            $since = $request->query('since');
            if ($since) {
                $query->where('created_at', '>', \Carbon\Carbon::createFromTimestampMs((int) $since));
            }
        }

        $messages = $query->get()->map(function ($msg) use ($code, $peerId) {
            $data = [
                'id' => $msg->id,
                'type' => $msg->type,
                // Server-computed ownership: peer ids are not exposed to clients.
                'is_mine' => $msg->peer_id === $peerId,
                'created_at' => $msg->created_at->valueOf(),
            ];

            if ($msg->type === 'text') {
                $data['content'] = $this->decryptContent($msg->content, (bool) $msg->is_encrypted);
            } else            if ($msg->type === 'file' || $msg->type === 'image') {
                $data['file_name'] = $msg->file_name;
                $data['image_path'] = $msg->image_path;
                $data['image_mime'] = $msg->image_mime;
                $data['image_size'] = $msg->image_size;
                $data['view_url'] = $msg->image_path
                    ? URL::temporarySignedRoute('portal.attachment', now()->addMinutes(5), [
                        'code' => $code,
                        'messageId' => $msg->id,
                    ])
                    : null;
                $data['download_url'] = $msg->image_path
                    ? URL::temporarySignedRoute('portal.download', now()->addMinutes(5), [
                        'code' => $code,
                        'messageId' => $msg->id,
                    ])
                    : null;
            }

            return $data;
        })->values()->toArray();

        return response()->json([
            'status' => 'active',
            'messages' => $messages,
            'peer_count' => $peerCount,
            'expires_at' => $session->expires_at->valueOf(),
        ]);
    }

    public function sendMessage(Request $request, $code)
    {
        $hashedCode = hash('sha256', $code);
        $session = PortalSession::where('code', $hashedCode)->first();

        if (!$session || $session->status !== 'active' || now()->gt($session->expires_at)) {
            return response()->json(['status' => 'error', 'message' => 'Portal not available'], 404);
        }

        $peerId = $this->getPeerId($request, $code);
        $this->touchPeer($session, $peerId);
        if (!$peerId || !in_array($peerId, $session->peer_ids ?? [])) {
            return response()->json(['status' => 'error', 'message' => 'Not a participant'], 403);
        }

        if ($session->messages()->count() >= self::MAX_MESSAGES_PER_PORTAL) {
            return response()->json([
                'status' => 'error',
                'message' => 'This portal has reached its message limit',
            ], 429);
        }

        $type = $request->input('type');

        if ($type === 'text') {
            $validator = Validator::make($request->all(), [
                'content' => 'required|string|max:50000',
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 'error', 'message' => 'Content is required'], 422);
            }

            $message = $session->messages()->create([
                'type' => 'text',
                'content' => Crypt::encryptString($request->input('content')),
                'is_encrypted' => true,
                'peer_id' => $peerId,
                'created_at' => now(),
            ]);

            return response()->json([
                'status' => 'ok',
                'message_id' => $message->id,
                'created_at' => $message->created_at->valueOf(),
            ]);
        }

        if ($type === 'image') {
            // Exclude SVG: Laravel's `image` rule accepts it, and an SVG served
            // inline can execute script in the app origin (stored XSS).
            $validator = Validator::make($request->all(), [
                'file' => 'required|file|mimes:jpg,jpeg,png,gif,webp|max:' . self::IMAGE_MAX_SIZE,
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 'error', 'message' => 'Invalid image file'], 422);
            }

            $file = $request->file('file');
            return $this->storeFileMessage($session, $peerId, $file, 'image');
        }

        if ($type === 'file') {
            $validator = Validator::make($request->all(), [
                'file' => 'required|file',
                'attachment_type' => 'required|in:' . implode(',', self::ALLOWED_TYPES),
            ]);

            if ($validator->fails()) {
                return response()->json(['status' => 'error', 'message' => 'Invalid file'], 422);
            }

            $attachmentType = $request->input('attachment_type');

            // The generic file branch must still enforce the image allowlist so
            // an SVG cannot be smuggled in as attachment_type=image.
            if ($attachmentType === 'image') {
                $imageValidator = Validator::make($request->all(), [
                    'file' => 'required|file|mimes:jpg,jpeg,png,gif,webp',
                ]);

                if ($imageValidator->fails()) {
                    return response()->json(['status' => 'error', 'message' => 'Invalid image file'], 422);
                }
            }

            $sizeRules = [
                'image' => self::IMAGE_MAX_SIZE,
                'pdf' => self::LARGE_MAX_SIZE,
                'mp4' => self::LARGE_MAX_SIZE,
                'zip' => self::LARGE_MAX_SIZE,
            ];
            $maxSize = $sizeRules[$attachmentType] ?? self::IMAGE_MAX_SIZE;

            if ($request->file('file')->getSize() > $maxSize * 1024) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'File exceeds maximum size for ' . $attachmentType,
                ], 413);
            }

            $file = $request->file('file');
            return $this->storeFileMessage($session, $peerId, $file, 'file');
        }

        return response()->json(['status' => 'error', 'message' => 'Unknown message type'], 400);
    }

    private function storeFileMessage(PortalSession $session, string $peerId, $file, string $messageType)
    {
        $mime = $file->getMimeType();
        $originalName = $this->sanitizeFileName($file->getClientOriginalName());

        // Never trust the client extension: map the detected MIME to an
        // allowlisted extension instead (prevents storing .php/.html).
        $ext = $this->safeExtension($mime);

        $filename = Str::uuid()->toString() . '.' . $ext;

        // Stage the plaintext, encrypt into the final path, then drop it.
        $plainPath = $file->storeAs('notery_tmp', $filename);
        $path = 'notery/' . $filename;

        EncryptedFile::encryptFile(
            Storage::disk('local')->path($plainPath),
            Storage::disk('local')->path($path)
        );
        Storage::disk('local')->delete($plainPath);

        $message = $session->messages()->create([
            'type' => $messageType,
            'file_name' => $originalName,
            'image_path' => $path,
            'image_mime' => $mime,
            'image_size' => $file->getSize(),
            'is_encrypted' => true,
            'peer_id' => $peerId,
            'created_at' => now(),
        ]);

        return response()->json([
            'status' => 'ok',
            'message_id' => $message->id,
            'created_at' => $message->created_at->valueOf(),
        ]);
    }

    public function storeChunk(Request $request, $code)
    {
        try {
            $hashedCode = hash('sha256', $code);
            $session = PortalSession::where('code', $hashedCode)->first();

            if (!$session || $session->status !== 'active' || now()->gt($session->expires_at)) {
                return response()->json(['status' => 'error', 'message' => 'Portal not available'], 404);
            }

            $peerId = $this->getPeerId($request, $code);
            $this->touchPeer($session, $peerId);
            if (!$peerId || !in_array($peerId, $session->peer_ids ?? [])) {
                return response()->json(['status' => 'error', 'message' => 'Not a participant'], 403);
            }

            $validator = Validator::make($request->all(), [
                'upload_id' => ['required', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i'],
                'file_index' => 'required|integer|min:0',
                'chunk_index' => 'required|integer|min:0',
                'total_chunks' => 'required|integer|min:1',
                'original_name' => 'required|string|max:1024',
                'attachment_type' => 'required|string|in:' . implode(',', self::ALLOWED_TYPES),
                'chunk' => 'required|file',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()->toArray(),
                ], 422);
            }

            $validated = $validator->validated();

            if ((int) $validated['chunk_index'] >= (int) $validated['total_chunks']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'chunk_index must be less than total_chunks',
                ], 422);
            }

            $chunkFile = $request->file('chunk');

            if ($chunkFile->getSize() > self::MAX_CHUNK_SIZE) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Chunk exceeds maximum allowed size',
                ], 413);
            }

            $uploadId = $validated['upload_id'];
            $fileIndex = (int) $validated['file_index'];
            $chunkIndex = (int) $validated['chunk_index'];
            $totalChunks = (int) $validated['total_chunks'];
            $originalName = $validated['original_name'];
            $attachmentType = $validated['attachment_type'];

            // Server-side caps: the client-side limits are advisory only.
            $limits = $this->limitsForType($attachmentType);

            if ($fileIndex >= self::MAX_FILES_PER_UPLOAD) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Too many files in this upload',
                ], 422);
            }

            if ($totalChunks > $limits['max_chunks']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'File is too large (too many chunks)',
                ], 413);
            }

            if ($chunkIndex === 0) {
                $chunkData = file_get_contents($chunkFile->getRealPath());
                $magicError = $this->validateMagicBytes($attachmentType, $chunkData);
                if ($magicError !== null) {
                    return response()->json([
                        'status' => 'error',
                        'message' => $magicError,
                    ], 422);
                }
            }

            $chunkDir = 'chunks/' . $uploadId;
            $chunkFullDir = Storage::disk('local')->path($chunkDir);
            if (!is_dir($chunkFullDir)) {
                mkdir($chunkFullDir, 0755, true);
            }

            $chunkPath = $chunkDir . '/' . $fileIndex . '_' . $chunkIndex;
            $moved = rename($chunkFile->getRealPath(), Storage::disk('local')->path($chunkPath));
            if (!$moved) {
                Storage::disk('local')->put($chunkPath, file_get_contents($chunkFile->getRealPath()));
            }

            // Serialize the manifest read-modify-write so concurrent chunk
            // requests cannot clobber each other's received_chunks.
            try {
                $fileManifest = Cache::lock('upload-manifest:' . $uploadId, 10)->block(
                    5,
                    function () use ($uploadId, $fileIndex, $chunkIndex, $totalChunks, $originalName, $attachmentType, $code) {
                        $manifest = $this->readManifest($uploadId);
                        if ($manifest === null) {
                            $manifest = [
                                'upload_id' => $uploadId,
                                'created_at' => now()->toIso8601String(),
                                'attachment_type' => $attachmentType,
                                'portal_code' => $code,
                                'files' => [],
                            ];
                        }

                        if (!isset($manifest['files'][(string) $fileIndex])) {
                            $manifest['files'][(string) $fileIndex] = [
                                'original_name' => $originalName,
                                'total_chunks' => $totalChunks,
                                'received_chunks' => [],
                            ];
                        } elseif ((int) $manifest['files'][(string) $fileIndex]['total_chunks'] !== $totalChunks) {
                            throw new UploadValidationException(
                                'Chunk metadata does not match earlier chunks for this file',
                                409
                            );
                        }

                        if (!in_array($chunkIndex, $manifest['files'][(string) $fileIndex]['received_chunks'], true)) {
                            $manifest['files'][(string) $fileIndex]['received_chunks'][] = $chunkIndex;
                            sort($manifest['files'][(string) $fileIndex]['received_chunks']);
                        }

                        $this->writeManifest($uploadId, $manifest);

                        return $manifest['files'][(string) $fileIndex];
                    }
                );
            } catch (LockTimeoutException $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Upload is busy, please retry',
                ], 503);
            }


            return response()->json([
                'status' => 'ok',
                'chunk_index' => $chunkIndex,
                'received_chunks' => $fileManifest['received_chunks'],
                'total_chunks' => $totalChunks,
            ]);
        } catch (UploadValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $e->status());
        } catch (LockTimeoutException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Upload is busy, please retry',
            ], 503);
        } catch (\Exception $e) {
            Log::error('Portal chunk upload failed', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error',
            ], 500);
        }
    }

    public function assemble(Request $request, $code)
    {
        try {
            $hashedCode = hash('sha256', $code);
            $session = PortalSession::where('code', $hashedCode)->first();

            if (!$session || $session->status !== 'active' || now()->gt($session->expires_at)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Portal not available',
                ], 404);
            }

            $peerId = $this->getPeerId($request, $code);
            $this->touchPeer($session, $peerId);
            if (!$peerId || !in_array($peerId, $session->peer_ids ?? [])) {
                return response()->json(['status' => 'error', 'message' => 'Not a participant'], 403);
            }

            $validator = Validator::make($request->all(), [
                'upload_id' => ['required', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i'],
                'attachment_type' => 'nullable|in:' . implode(',', self::ALLOWED_TYPES),
                'files' => 'nullable|array',
                'files.*.index' => 'required_with:files|integer|min:0',
                'files.*.original_name' => 'required_with:files|string|max:1024',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()->toArray(),
                ], 422);
            }

            $validated = $validator->validated();
            $uploadId = $validated['upload_id'];
            $attachmentType = $validated['attachment_type'] ?? null;
            $filesMeta = $validated['files'] ?? [];

            $manifest = $this->readManifest($uploadId);
            if ($manifest === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Upload session not found or expired',
                ], 404);
            }

            if (($manifest['portal_code'] ?? null) !== $code) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Upload session does not belong to this portal',
                ], 403);
            }

            if (!empty($filesMeta)) {
                $missing = $this->findMissingChunks($manifest, $filesMeta);
                if (!empty($missing)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Incomplete upload. Missing chunks: ' . json_encode($missing),
                    ], 400);
                }
            }

            if (count($filesMeta) > self::MAX_FILES_PER_UPLOAD) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Too many files in this upload',
                ], 422);
            }

            $preflightLimits = $this->limitsForType($attachmentType);
            $totalUploadBytes = 0;

            foreach ($filesMeta as $fileMeta) {
                $idx = (int) $fileMeta['index'];
                $fm = $manifest['files'][(string) $idx] ?? null;
                if ($fm === null) {
                    continue;
                }
                for ($ci = 0; $ci < (int) $fm['total_chunks']; $ci++) {
                    $cfp = Storage::disk('local')->path('chunks/' . $uploadId . '/' . $idx . '_' . $ci);
                    if (is_file($cfp)) {
                        $totalUploadBytes += (int) filesize($cfp);
                    }
                }
            }

            if ($totalUploadBytes > $preflightLimits['max_bytes']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Upload exceeds the maximum size for ' . ($attachmentType ?? 'this type'),
                ], 413);
            }

            // Assemble to disk first, then persist message rows in a
            // transaction, and only delete the chunks after the commit. The old
            // version created rows and deleted chunks with no transaction, so a
            // failure could orphan files or lose chunks on a rollback.
            $assembledFiles = [];
            $writtenPaths = [];

            try {
                if (!empty($filesMeta)) {
                    foreach ($filesMeta as $fileMeta) {
                        $fileIndex = (int) $fileMeta['index'];
                        $originalName = $this->sanitizeFileName((string) $fileMeta['original_name']);
                        $fileManifest = $manifest['files'][(string) $fileIndex] ?? null;

                        if ($fileManifest === null) {
                            throw new \RuntimeException("File index {$fileIndex} not found in manifest");
                        }

                        $totalChunks = $fileManifest['total_chunks'];
                        // Derive the extension from the declared type, never from
                        // the client filename, so .php/.html cannot be stored.
                        $ext = $this->extensionForType($attachmentType);

                        $filename = Str::uuid()->toString() . '.' . $ext;
                        $outputPath = 'notery/' . $filename;
                        $fullOutputPath = Storage::disk('local')->path($outputPath);

                        $noteryDir = dirname($fullOutputPath);
                        if (!is_dir($noteryDir)) {
                            mkdir($noteryDir, 0755, true);
                        }

                        $writtenPaths[] = $fullOutputPath;

                        // Stream the chunks through the encrypting writer so
                        // the file is never stored in plaintext.
                        $writer = new EncryptedFileWriter($fullOutputPath);

                        $totalSize = 0;
                        $header = '';

                        try {
                            for ($ci = 0; $ci < $totalChunks; $ci++) {
                                $cp = 'chunks/' . $uploadId . '/' . $fileIndex . '_' . $ci;
                                $cfp = Storage::disk('local')->path($cp);

                                if (!file_exists($cfp)) {
                                    throw new \RuntimeException("Chunk file missing: {$cp}");
                                }

                                $chunkData = file_get_contents($cfp);
                                if ($chunkData === false) {
                                    throw new \RuntimeException("Failed to read chunk: {$cp}");
                                }

                                if (strlen($header) < 12) {
                                    $header .= substr($chunkData, 0, 12 - strlen($header));
                                }

                                $writer->write($chunkData);
                                $totalSize += strlen($chunkData);
                            }

                            $writer->close();
                        } catch (\Throwable $e) {
                            $writer->abort();
                            throw $e;
                        }

                        $limits = $this->limitsForType($attachmentType);
                        if ($totalSize > $limits['max_bytes']) {
                            throw new UploadValidationException(
                                'File exceeds the maximum size for ' . ($attachmentType ?? 'this type'),
                                413
                            );
                        }

                        $mime = $this->detectMimeFromBuffer($header, $attachmentType);

                        if (!$this->mimeMatchesType($mime, $attachmentType)) {
                            throw new UploadValidationException(
                                'File contents do not match the declared type (' . ($attachmentType ?? 'unknown') . ')'
                            );
                        }

                        if ($attachmentType !== null) {
                            $magicError = $this->validateMagicBytes($attachmentType, $header);
                            if ($magicError !== null) {
                                throw new UploadValidationException($magicError);
                            }
                        }

                        $assembledFiles[] = [
                            'path' => $outputPath,
                            'name' => $originalName,
                            'mime' => $mime,
                            'size' => $totalSize,
                        ];
                    }
                }

                // Persist all message rows atomically.
                \DB::transaction(function () use ($session, $peerId, $assembledFiles) {
                    foreach ($assembledFiles as $file) {
                        $session->messages()->create([
                            'type' => 'file',
                            'file_name' => $file['name'],
                            'image_path' => $file['path'],
                            'image_mime' => $file['mime'],
                            'image_size' => $file['size'],
                            'is_encrypted' => true,
                            'peer_id' => $peerId,
                            'created_at' => now(),
                        ]);
                    }
                });

                // Only remove the chunks once the rows are committed.
                Storage::disk('local')->deleteDirectory('chunks/' . $uploadId);

                return response()->json([
                    'status' => 'ok',
                    'files_count' => count($assembledFiles),
                ]);
            } catch (\Throwable $e) {
                // Remove any output files written before the failure.
                foreach ($writtenPaths as $path) {
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }

                throw $e;
            }
        } catch (UploadValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $e->status());
        } catch (\RuntimeException $e) {
            Log::error('Portal assembly failed', [
                'error' => $e->getMessage(),
                'upload_id' => $request->input('upload_id'),
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('Portal assembly failed (unexpected)', [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['status' => 'error', 'message' => 'Internal server error'], 500);
        }
    }

    public function downloadAttachment(Request $request, $code, $messageId)
    {
        $hashedCode = hash('sha256', $code);
        $session = PortalSession::where('code', $hashedCode)->first();

        if (!$session) {
            abort(404);
        }

        $message = PortalMessage::where('id', $messageId)
            ->where('portal_session_id', $session->id)
            ->first();

        if (!$message || !$message->image_path) {
            abort(404);
        }

        if (!Storage::exists($message->image_path)) {
            abort(404);
        }

        $this->assertPortalParticipant($request, $session, $code);

        // Only serve a strict allowlist of raster images inline. Anything else
        // (including SVG) is forced to download so it can never execute in the
        // app origin, and nosniff stops MIME confusion in the browser.
        $inlineSafe = in_array($message->image_mime, [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ], true);

        $sourcePath = Storage::path($message->image_path);
        $deleteAfterSend = false;

        if ($message->is_encrypted && EncryptedFile::isEncrypted($sourcePath)) {
            $sourcePath = EncryptedFile::decryptToTemp($sourcePath);
            $deleteAfterSend = true;
        }

        $response = response()->file(
            $sourcePath,
            [
                'Content-Type' => $message->image_mime ?? 'application/octet-stream',
                'Content-Disposition' => ($inlineSafe ? 'inline' : 'attachment') . '; filename="portal-file-' . $message->id . '"',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );

        return $deleteAfterSend ? $response->deleteFileAfterSend(true) : $response;
    }

    public function forceDownload(Request $request, $code, $messageId)
    {
        $hashedCode = hash('sha256', $code);
        $session = PortalSession::where('code', $hashedCode)->first();

        if (!$session) {
            abort(404);
        }

        $message = PortalMessage::where('id', $messageId)
            ->where('portal_session_id', $session->id)
            ->first();

        if (!$message || !$message->image_path) {
            abort(404);
        }

        if (!Storage::exists($message->image_path)) {
            abort(404);
        }

        $this->assertPortalParticipant($request, $session, $code);

        // Sanitize defensively: legacy rows may hold a name with a path
        // separator, which makes Storage::download throw a 500.
        $downloadName = $this->sanitizeFileName(
            $message->file_name ?? ('portal-file-' . $message->id)
        );

        $sourcePath = Storage::path($message->image_path);

        if ($message->is_encrypted && EncryptedFile::isEncrypted($sourcePath)) {
            $tmp = EncryptedFile::decryptToTemp($sourcePath);

            return response()->download($tmp, $downloadName, [
                'Content-Type' => $message->image_mime ?? 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ])->deleteFileAfterSend(true);
        }

        return Storage::download(
            $message->image_path,
            $downloadName,
            [
                'Content-Type' => $message->image_mime ?? 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    public function close(Request $request, $code)
    {
        $hashedCode = hash('sha256', $code);
        $session = PortalSession::where('code', $hashedCode)->first();

        if (!$session) {
            return response()->json(['status' => 'error', 'message' => 'Portal not found'], 404);
        }

        $peerId = $this->getPeerId($request, $code);
        $this->touchPeer($session, $peerId);
        if (!$peerId || !in_array($peerId, $session->peer_ids ?? [])) {
            return response()->json(['status' => 'error', 'message' => 'Not a participant'], 403);
        }

        $this->deleteSessionFiles($session);

        $session->status = 'closed';
        $session->save();

        return response()->json(['status' => 'ok']);
    }

    private function deleteSessionFiles(PortalSession $session): void
    {
        foreach ($session->messages as $message) {
            if (!empty($message->image_path) && Storage::exists($message->image_path)) {
                Storage::delete($message->image_path);
            }
        }
    }

    public static function deleteExpiredSessions(): int
    {
        $expired = PortalSession::where('expires_at', '<', now())
            ->orWhere('status', 'closed')
            ->get();

        $count = 0;
        foreach ($expired as $session) {
            foreach ($session->messages as $message) {
                if (!empty($message->image_path) && Storage::exists($message->image_path)) {
                    Storage::delete($message->image_path);
                }
            }
            $session->delete();
            $count++;
        }

        return $count;
    }

    private function getPeerId(Request $request, string $code): ?string
    {
        // Peer identity comes only from the session. Accepting a client-supplied
        // peer_id let anyone holding the UUID act as that peer, and put the id
        // into URLs and access logs.
        return $request->session()->get('portal_peer_' . $code);
    }

    private function generateUniqueCode(int $length = 4, int $maxAttempts = 50): string
    {
        $chars = '1234567890';
        $charLength = strlen($chars);

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $randomString = '';
            for ($i = 0; $i < $length; $i++) {
                $randomString .= $chars[random_int(0, $charLength - 1)];
            }
            $hashedCode = hash('sha256', $randomString);

            // Codes are shared with the saves table, so check both namespaces.
            $taken = PortalSession::where('code', $hashedCode)->exists()
                || Save::where('code', $hashedCode)->exists();

            if (!$taken) {
                return $randomString;
            }
        }

        throw new \RuntimeException(
            'Unable to allocate a unique portal code after ' . $maxAttempts . ' attempts'
        );
    }

    private function chunkDir(string $uploadId): string
    {
        return 'chunks/' . $uploadId;
    }

    private function readManifest(string $uploadId): ?array
    {
        $manifestPath = $this->chunkDir($uploadId) . '/manifest.json';
        if (!Storage::disk('local')->exists($manifestPath)) {
            return null;
        }
        $json = Storage::disk('local')->get($manifestPath);
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    private function writeManifest(string $uploadId, array $data): void
    {
        $dir = $this->chunkDir($uploadId);
        $disk = Storage::disk('local');
        $tmpPath = $dir . '/manifest.tmp';
        $finalPath = $dir . '/manifest.json';

        $disk->put($tmpPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        rename($disk->path($tmpPath), $disk->path($finalPath));
    }

    private function validateMagicBytes(string $type, string $data): ?string
    {
        if (strlen($data) < 12) {
            return 'Chunk too small to validate file type';
        }

        switch ($type) {
            case 'image':
                $header4 = substr($data, 0, 4);
                $header3 = substr($data, 0, 3);
                if ($header3 === "\xFF\xD8\xFF") return null;
                if ($header4 === "\x89PNG") return null;
                if (substr($data, 0, 4) === "GIF8") return null;
                if ($header4 === "RIFF" && substr($data, 8, 4) === "WEBP") return null;
                return 'File does not appear to be a valid image (expected JPEG, PNG, GIF, or WebP header)';

            case 'pdf':
                if (substr($data, 0, 4) !== '%PDF') {
                    return 'File does not start with %PDF — not a valid PDF';
                }
                return null;

            case 'mp4':
                $boxType = substr($data, 4, 4);
                if ($boxType !== 'ftyp' && $boxType !== 'moov' && $boxType !== 'mdat') {
                    return 'File does not appear to be a valid MP4 (missing ftyp/moov box)';
                }
                return null;

            case 'zip':
                $header4 = substr($data, 0, 4);
                if ($header4 !== "PK\x03\x04" && $header4 !== "PK\x05\x06" && $header4 !== "PK\x07\x08") {
                    return 'File does not start with PK header — not a valid ZIP file';
                }
                return null;

            default:
                return 'Unknown attachment type';
        }
    }

    private function detectMimeType(string $fullPath, ?string $attachmentType): string
    {
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($fullPath);
            if ($detected && $detected !== 'application/octet-stream') {
                return $detected;
            }
        }
        return $this->fallbackExtension($attachmentType);
    }

    private function fallbackExtension(?string $type): string
    {
        return match ($type) {
            'image' => 'image/jpeg',
            'pdf' => 'application/pdf',
            'mp4' => 'video/mp4',
            'zip' => 'application/zip',
            default => 'application/octet-stream',
        };
    }

    private function findMissingChunks(array $manifest, array $filesMeta): array
    {
        $missing = [];
        foreach ($filesMeta as $fileMeta) {
            $idx = (string) $fileMeta['index'];
            $fileManifest = $manifest['files'][$idx] ?? null;
            if ($fileManifest === null) {
                $missing[] = "file {$idx}: no chunks received";
                continue;
            }
            $total = $fileManifest['total_chunks'];
            $received = $fileManifest['received_chunks'] ?? [];
            $expected = range(0, $total - 1);
            $diff = array_diff($expected, $received);
            if (!empty($diff)) {
                $missing[] = "file {$idx}: missing chunks [" . implode(',', $diff) . ']';
            }
        }
        return $missing;
    }

    /**
     * Sanitize a client-supplied filename for storage and display.
     *
     * Strips path components, control characters, and the characters that
     * break out of HTML attributes or HTTP headers (quotes, angle brackets).
     */
    private function sanitizeFileName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F"\'<>|:*?]+/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'file';
        }

        return mb_substr($name, 0, 255);
    }

    /**
     * Map a detected MIME type to an allowlisted file extension. The client
     * extension is never used, so .php/.html cannot be stored.
     */
    private function safeExtension(string $mime): string
    {
        return match (true) {
            $mime === 'image/jpeg' => 'jpg',
            $mime === 'image/png' => 'png',
            $mime === 'image/gif' => 'gif',
            $mime === 'image/webp' => 'webp',
            $mime === 'application/pdf' => 'pdf',
            $mime === 'video/mp4' => 'mp4',
            in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/x-zip'], true) => 'zip',
            default => 'bin',
        };
    }

    /**
     * Canonical extension for a declared attachment type.
     */
    private function extensionForType(?string $type): string
    {
        return match ($type) {
            'image' => 'jpg',
            'pdf' => 'pdf',
            'mp4' => 'mp4',
            'zip' => 'zip',
            default => 'bin',
        };
    }

    /**
     * Record that a peer is still active, releasing its slot after the idle
     * window if it disappears. Writes are throttled to avoid a DB write on
     * every 1.5s poll.
     */
    private function touchPeer(PortalSession $session, ?string $peerId): void
    {
        if (!$peerId || !in_array($peerId, $session->peer_ids ?? [], true)) {
            return;
        }

        $seenAt = $session->peer_seen_at ?? [];
        $now = now()->getTimestamp();

        if (($seenAt[$peerId] ?? 0) > $now - 30) {
            return;
        }

        $seenAt[$peerId] = $now;
        $session->peer_seen_at = $seenAt;
        $session->save();
    }

    /**
     * Server-side size and chunk-count limits per attachment type.
     *
     * @return array{max_bytes: int, max_chunks: int}
     */
    private function limitsForType(?string $type): array
    {
        return match ($type) {
            'image' => ['max_bytes' => 20 * 1024 * 1024, 'max_chunks' => 50],
            'pdf'   => ['max_bytes' => 500 * 1024 * 1024, 'max_chunks' => 200],
            'mp4'   => ['max_bytes' => 500 * 1024 * 1024, 'max_chunks' => 200],
            'zip'   => ['max_bytes' => 500 * 1024 * 1024, 'max_chunks' => 200],
            default => ['max_bytes' => 20 * 1024 * 1024, 'max_chunks' => 50],
        };
    }

    /**
     * Whether a detected MIME type is consistent with the declared type.
     */
    private function mimeMatchesType(?string $mime, ?string $type): bool
    {
        if ($mime === null || $type === null) {
            return true;
        }

        return match ($type) {
            'image' => str_starts_with($mime, 'image/'),
            'pdf'   => $mime === 'application/pdf',
            'mp4'   => str_starts_with($mime, 'video/') || $mime === 'application/octet-stream',
            'zip'   => str_contains($mime, 'zip') || $mime === 'application/octet-stream',
            default => true,
        };
    }

    /**
     * Decrypt a message body, tolerating legacy plaintext rows.
     */
    private function decryptContent(?string $content, bool $encrypted): ?string
    {
        if ($content === null) {
            return null;
        }

        if (!$encrypted) {
            return $content;
        }

        try {
            return Crypt::decryptString($content);
        } catch (\Throwable $e) {
            // Row predates encryption or was written by an older build.
            return $content;
        }
    }

    /**
     * Detect a MIME type from an in-memory header (used because assembled
     * files are encrypted on disk and cannot be inspected with finfo_file).
     */
    private function detectMimeFromBuffer(string $bytes, ?string $attachmentType): string
    {
        if ($bytes !== '' && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_buffer($finfo, $bytes);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '' && $detected !== 'application/octet-stream') {
                    return $detected;
                }
            }
        }

        return $this->fallbackExtension($attachmentType);
    }

    /**
     * A signed download URL is only useful to an active participant of a live
     * portal. Binding it to the session stops leaked links from being replayed
     * by anyone else, including after the portal closes or expires.
     */
    private function assertPortalParticipant(Request $request, PortalSession $session, string $code): void
    {
        if ($session->status !== 'active' || now()->gt($session->expires_at)) {
            abort(410, 'Portal is no longer active');
        }

        $peerId = $request->session()->get('portal_peer_' . $code);

        if (!$peerId || !in_array($peerId, $session->peer_ids ?? [], true)) {
            abort(403, 'Not a participant');
        }
    }
}
