<?php

namespace App\Http\Controllers;

use App\Models\PortalSession;
use App\Models\PortalMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
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

        $code = $this->generateUniqueCode();
        $hashedCode = hash('sha256', $code);

        $peerId = Str::uuid()->toString();

        PortalSession::create([
            'code' => $hashedCode,
            'duration_minutes' => $duration,
            'expires_at' => now()->addMinutes($duration),
            'status' => 'active',
            'peer_ids' => [],
        ]);

        $request->session()->put('portal_peer_' . $code, $peerId);
        $request->session()->save();

        Log::info('Portal created', ['code' => $code, 'peer_id' => $peerId, 'session_id' => $request->session()->getId()]);

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
            return view('portal.expired', compact('errorMessage'));
        }

        $hashedCode = hash('sha256', $code);
        $session = PortalSession::where('code', $hashedCode)->first();

        if (!$session) {
            $errorMessage = 'This portal does not exist';
            return view('portal.expired', compact('errorMessage'));
        }

        if ($session->status === 'closed') {
            $errorMessage = 'This portal has been closed';
            return view('portal.expired', compact('errorMessage'));
        }

        if (now()->gt($session->expires_at)) {
            $errorMessage = 'This portal has expired';
            return view('portal.expired', compact('errorMessage'));
        }

        Log::info('Portal show: looking up session', [
            'code' => $code,
            'session_id' => $request->session()->getId(),
            'session_peer_key' => $request->session()->get('portal_peer_' . $code),
        ]);

        $peerIds = $session->peer_ids ?? [];
        $peerId = null;

        $sessionPeerId = $request->session()->get('portal_peer_' . $code);
        Log::info('Portal show: peer assignment', [
            'code' => $code,
            'session_peer_id' => $sessionPeerId,
            'db_peer_ids' => $peerIds,
            'peer_count' => count($peerIds),
        ]);

        if ($sessionPeerId && in_array($sessionPeerId, $peerIds)) {
            $peerId = $sessionPeerId;
            Log::info('Portal show: reusing existing peer', ['peer_id' => $peerId]);
        } elseif (count($peerIds) < 2) {
            $peerId = Str::uuid()->toString();
            $peerIds[] = $peerId;
            $request->session()->put('portal_peer_' . $code, $peerId);
            $session->peer_ids = array_values($peerIds);
            $session->save();
            Log::info('Portal show: assigned new peer', [
                'peer_id' => $peerId,
                'db_peer_ids_now' => $peerIds,
                'session_peer_id_now' => $request->session()->get('portal_peer_' . $code),
            ]);
        } else {
            Log::warning('Portal show: portal full', [
                'code' => $code,
                'peer_ids' => $peerIds,
                'session_peer_id' => $sessionPeerId,
            ]);
            $errorMessage = 'This portal is full (maximum 2 participants)';
            return view('portal.expired', compact('errorMessage'));
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
        Log::info('Portal poll: request received', [
            'code' => $code,
            'query_params' => $request->query(),
            'session_id' => $request->session()->getId(),
        ]);

        $hashedCode = hash('sha256', $code);
        $session = PortalSession::where('code', $hashedCode)->first();

        if (!$session) {
            Log::warning('Portal poll: session not found', ['code' => $code, 'hashed' => $hashedCode]);
            return response()->json([
                'status' => 'error',
                'message' => 'Portal not found',
            ], 404);
        }

        $peerId = $this->getPeerId($request, $code);

        Log::info('Portal poll', [
            'code' => $code,
            'peer_id' => $peerId,
            'session_peer' => $request->session()->get('portal_peer_' . $code),
            'query_peer' => $request->query('peer_id'),
            'db_peer_ids' => $session->peer_ids,
            'db_peer_count' => count($session->peer_ids ?? []),
        ]);

        if (!$peerId || !in_array($peerId, $session->peer_ids ?? [])) {
            Log::warning('Portal poll: not a participant', [
                'code' => $code,
                'peer_id' => $peerId,
                'db_peer_ids' => $session->peer_ids,
            ]);
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

        $since = $request->query('since');
        $query = $session->messages()->orderBy('created_at', 'asc');

        if ($since) {
            $sinceTimestamp = \Carbon\Carbon::createFromTimestampMs($since);
            $query->where('created_at', '>', $sinceTimestamp);
        }

        $messages = $query->get()->map(function ($msg) use ($code) {
            $data = [
                'id' => $msg->id,
                'type' => $msg->type,
                'peer_id' => $msg->peer_id,
                'created_at' => $msg->created_at->valueOf(),
            ];

            if ($msg->type === 'text') {
                $data['content'] = $msg->content;
            } else            if ($msg->type === 'file' || $msg->type === 'image') {
                $data['file_name'] = $msg->file_name;
                $data['image_path'] = $msg->image_path;
                $data['image_mime'] = $msg->image_mime;
                $data['image_size'] = $msg->image_size;
                $data['view_url'] = $msg->image_path
                    ? URL::temporarySignedRoute('portal.attachment', now()->addMinutes(10), [
                        'code' => $code,
                        'messageId' => $msg->id,
                    ])
                    : null;
                $data['download_url'] = $msg->image_path
                    ? URL::temporarySignedRoute('portal.download', now()->addMinutes(10), [
                        'code' => $code,
                        'messageId' => $msg->id,
                    ])
                    : null;
            }

            return $data;
        })->values()->toArray();

        Log::info('Portal poll: success', [
            'code' => $code,
            'peer_count' => $peerCount,
            'message_count' => count($messages),
        ]);

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
        if (!$peerId || !in_array($peerId, $session->peer_ids ?? [])) {
            return response()->json(['status' => 'error', 'message' => 'Not a participant'], 403);
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
                'content' => $request->input('content'),
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
            $validator = Validator::make($request->all(), [
                'file' => 'required|file|image|max:' . self::IMAGE_MAX_SIZE,
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
        $originalName = $file->getClientOriginalName();
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext === '' || $ext === 'bin') {
            $ext = match (true) {
                str_starts_with($mime, 'image/') => 'jpg',
                str_starts_with($mime, 'video/') => 'mp4',
                str_contains($mime, 'pdf') => 'pdf',
                str_contains($mime, 'zip') => 'zip',
                default => 'bin',
            };
        }

        $filename = Str::uuid()->toString() . '.' . $ext;
        $path = $file->storeAs('notery', $filename);

        $message = $session->messages()->create([
            'type' => $messageType,
            'file_name' => $originalName,
            'image_path' => $path,
            'image_mime' => $mime,
            'image_size' => $file->getSize(),
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
            }

            if (!in_array($chunkIndex, $manifest['files'][(string) $fileIndex]['received_chunks'], true)) {
                $manifest['files'][(string) $fileIndex]['received_chunks'][] = $chunkIndex;
                sort($manifest['files'][(string) $fileIndex]['received_chunks']);
            }

            $this->writeManifest($uploadId, $manifest);

            $fileManifest = $manifest['files'][(string) $fileIndex];

            return response()->json([
                'status' => 'ok',
                'chunk_index' => $chunkIndex,
                'received_chunks' => $fileManifest['received_chunks'],
                'total_chunks' => $totalChunks,
            ]);
        } catch (\Exception $e) {
            Log::error('Portal chunk upload failed', [
                'error' => $e->getMessage(),
                'code' => $code,
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

            if (!empty($filesMeta)) {
                $missing = $this->findMissingChunks($manifest, $filesMeta);
                if (!empty($missing)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Incomplete upload. Missing chunks: ' . json_encode($missing),
                    ], 400);
                }
            }

            $assembledFiles = [];

            if (!empty($filesMeta)) {
                foreach ($filesMeta as $fileMeta) {
                    $fileIndex = (int) $fileMeta['index'];
                    $originalName = $fileMeta['original_name'];
                    $fileManifest = $manifest['files'][(string) $fileIndex] ?? null;

                    if ($fileManifest === null) {
                        throw new \RuntimeException("File index {$fileIndex} not found in manifest");
                    }

                    $totalChunks = $fileManifest['total_chunks'];
                    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                    if ($ext === '' || $ext === 'bin') {
                        $ext = $this->fallbackExtension($attachmentType);
                    }

                    $filename = Str::uuid()->toString() . '.' . $ext;
                    $outputPath = 'notery/' . $filename;
                    $fullOutputPath = Storage::disk('local')->path($outputPath);

                    $noteryDir = dirname($fullOutputPath);
                    if (!is_dir($noteryDir)) {
                        mkdir($noteryDir, 0755, true);
                    }

                    $out = fopen($fullOutputPath, 'wb');
                    if ($out === false) {
                        throw new \RuntimeException("Failed to open output file: {$fullOutputPath}");
                    }

                    $totalSize = 0;
                    for ($ci = 0; $ci < $totalChunks; $ci++) {
                        $cp = 'chunks/' . $uploadId . '/' . $fileIndex . '_' . $ci;
                        $cfp = Storage::disk('local')->path($cp);

                        if (!file_exists($cfp)) {
                            fclose($out);
                            if (file_exists($fullOutputPath)) unlink($fullOutputPath);
                            throw new \RuntimeException("Chunk file missing: {$cp}");
                        }

                        $chunkData = file_get_contents($cfp);
                        if ($chunkData === false) {
                            fclose($out);
                            if (file_exists($fullOutputPath)) unlink($fullOutputPath);
                            throw new \RuntimeException("Failed to read chunk: {$cp}");
                        }

                        $written = fwrite($out, $chunkData);
                        if ($written === false) {
                            fclose($out);
                            if (file_exists($fullOutputPath)) unlink($fullOutputPath);
                            throw new \RuntimeException("Failed to write to output file");
                        }
                        $totalSize += strlen($chunkData);
                    }

                    fclose($out);

                    $mime = $this->detectMimeType($fullOutputPath, $attachmentType);

                    $session->messages()->create([
                        'type' => 'file',
                        'file_name' => $originalName,
                        'image_path' => $outputPath,
                        'image_mime' => $mime,
                        'image_size' => $totalSize,
                        'peer_id' => $peerId,
                        'created_at' => now(),
                    ]);

                    $assembledFiles[] = ['path' => $outputPath];
                }
            }

            Storage::disk('local')->deleteDirectory('chunks/' . $uploadId);

            return response()->json([
                'status' => 'ok',
                'files_count' => count($assembledFiles),
            ]);
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

        $isImage = $message->image_mime && str_starts_with($message->image_mime, 'image/');
        $disposition = $isImage ? 'inline' : 'attachment';

        return response()->file(
            Storage::path($message->image_path),
            [
                'Content-Type' => $message->image_mime ?? 'application/octet-stream',
                'Content-Disposition' => $disposition . '; filename="portal-file-' . $message->id . '"',
            ]
        );
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

        return Storage::download(
            $message->image_path,
            $message->file_name ?? 'portal-file-' . $message->id,
            ['Content-Type' => $message->image_mime ?? 'application/octet-stream']
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
        return $request->session()->get('portal_peer_' . $code)
            ?? $request->query('peer_id')
            ?? $request->input('peer_id');
    }

    private function generateUniqueCode(int $length = 4): string
    {
        $chars = '1234567890';
        $charLength = strlen($chars);

        do {
            $randomString = '';
            for ($i = 0; $i < $length; $i++) {
                $randomString .= $chars[random_int(0, $charLength - 1)];
            }
            $hashedCode = hash('sha256', $randomString);
        } while (PortalSession::where('code', $hashedCode)->exists());

        return $randomString;
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
}
