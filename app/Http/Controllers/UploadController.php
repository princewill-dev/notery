<?php

namespace App\Http\Controllers;

use App\Models\Save;
use App\Models\SaveImage;
use App\Models\Stats;
use App\Models\PortalSession;
use App\Exceptions\UploadValidationException;
use App\Support\EncryptedFileWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    /**
     * Maximum size of a single chunk in bytes (5 MB + 1 MB overhead buffer).
     */
    private const MAX_CHUNK_SIZE = 6 * 1024 * 1024;

    /**
     * Upper bound on the number of files in a single upload session.
     */
    private const MAX_FILES_PER_UPLOAD = 20;

    /**
     * Allowed attachment types.
     */
    private const ALLOWED_TYPES = ['image', 'pdf', 'mp4', 'zip'];

    /**
     * Magic byte signatures for each supported type.
     * Each entry: [offset => [hex => label]] — all signatures at the offset must match.
     */
    private const MAGIC = [
        'image' => [
            // offset 0: check multiple image formats
            "\xFF\xD8\xFF"     => 'jpeg',
            "\x89PNG"          => 'png',   // 89 50 4E 47
            "GIF8"             => 'gif',    // 47 49 46 38
            "RIFF"             => 'webp',   // 52 49 46 46 — RIFF container (needs WEBP check after)
        ],
        'pdf' => [
            '%PDF'             => 'pdf',
        ],
        'mp4' => [
            // MP4: offset 4 must contain 'ftyp'
        ],
        'zip' => [
            "PK\x03\x04"       => 'zip',
            "PK\x05\x06"       => 'zip-empty',
            "PK\x07\x08"       => 'zip-spanned',
        ],
    ];

    /**
     * Receive a single chunk of a file upload.
     *
     * Expected multipart/form-data fields:
     *   - upload_id:      UUID v4 string
     *   - file_index:     zero-based index of this file in the batch
     *   - chunk_index:    zero-based index of this chunk within the file
     *   - total_chunks:   total number of chunks for this file
     *   - original_name:  original filename (for extension detection)
     *   - attachment_type: one of: image, pdf, mp4, zip
     *   - chunk:          the file blob (raw binary)
     */
    public function storeChunk(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'upload_id'       => ['required', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i'],
                'file_index'      => 'required|integer|min:0',
                'chunk_index'     => 'required|integer|min:0',
                'total_chunks'    => 'required|integer|min:1',
                'original_name'   => 'required|string|max:1024',
                'attachment_type' => 'required|string|in:' . implode(',', self::ALLOWED_TYPES),
                'chunk'           => 'required|file',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()->toArray(),
                ], 422);
            }

            $validated = $validator->validated();

            // Sanity: chunk_index must be less than total_chunks
            if ((int) $validated['chunk_index'] >= (int) $validated['total_chunks']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'chunk_index must be less than total_chunks',
                ], 422);
            }

            $chunkFile = $request->file('chunk');

            // Reject oversized chunks
            if ($chunkFile->getSize() > self::MAX_CHUNK_SIZE) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Chunk exceeds maximum allowed size of ' . (self::MAX_CHUNK_SIZE / 1024 / 1024) . ' MB',
                ], 413);
            }

            $uploadId   = $validated['upload_id'];
            $fileIndex  = (int) $validated['file_index'];
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

            // Validate magic bytes on the first chunk of each file
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

            // Ensure the chunk directory exists
            $chunkDir = $this->chunkDir($uploadId);
            $chunkFullDir = Storage::disk('local')->path($chunkDir);
            if (!is_dir($chunkFullDir)) {
                mkdir($chunkFullDir, 0755, true);
            }

            // Store the chunk file
            $chunkPath = $chunkDir . '/' . $fileIndex . '_' . $chunkIndex;
            // Use the real path directly for efficient streaming
            $moved = rename($chunkFile->getRealPath(), Storage::disk('local')->path($chunkPath));
            if (!$moved) {
                // Fallback: copy + delete
                Storage::disk('local')->put($chunkPath, file_get_contents($chunkFile->getRealPath()));
            }

            // Update the manifest atomically
            $manifest = $this->readManifest($uploadId);
            if ($manifest === null) {
                $manifest = [
                    'upload_id'   => $uploadId,
                    'created_at'  => now()->toIso8601String(),
                    'attachment_type' => $attachmentType,
                    'files'       => [],
                ];
            }

            if (!isset($manifest['files'][(string) $fileIndex])) {
                $manifest['files'][(string) $fileIndex] = [
                    'original_name'  => $originalName,
                    'total_chunks'   => $totalChunks,
                    'received_chunks' => [],
                ];
            } elseif ((int) $manifest['files'][(string) $fileIndex]['total_chunks'] !== $totalChunks) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Chunk metadata does not match earlier chunks for this file',
                ], 409);
            }

            // Mark this chunk as received (avoid duplicates)
            if (!in_array($chunkIndex, $manifest['files'][(string) $fileIndex]['received_chunks'], true)) {
                $manifest['files'][(string) $fileIndex]['received_chunks'][] = $chunkIndex;
                sort($manifest['files'][(string) $fileIndex]['received_chunks']);
            }

            $this->writeManifest($uploadId, $manifest);

            $fileManifest = $manifest['files'][(string) $fileIndex];

            return response()->json([
                'status'          => 'ok',
                'chunk_index'     => $chunkIndex,
                'received_chunks' => $fileManifest['received_chunks'],
                'total_chunks'    => $totalChunks,
            ]);
        } catch (\Exception $e) {
            Log::error('Chunk upload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Internal server error',
            ], 500);
        }
    }

    /**
     * Assemble all uploaded chunks into final files and create the Save record.
     *
     * Expected JSON body:
     *   - upload_id:       UUID v4 string
     *   - writeup:         string (note content)
     *   - attachment_type: string|null (one of: image, pdf, mp4, zip, or null if no files)
     *   - max_views:       int|null (1-100, defaults to 1)
     *   - files:           array of { index: int, original_name: string }
     */
    public function assemble(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'upload_id'       => ['required', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i'],
                'writeup'         => 'required|string',
                'attachment_type' => 'nullable|in:' . implode(',', self::ALLOWED_TYPES),
                'max_views'       => 'nullable|integer|min:1|max:100',
                'files'           => 'nullable|array',
                'files.*.index'   => 'required_with:files|integer|min:0',
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

            // Load and validate the manifest
            $manifest = $this->readManifest($uploadId);
            if ($manifest === null) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Upload session not found or expired. Please start a new upload.',
                ], 404);
            }

            // If there are files, verify all chunks are present
            if (!empty($filesMeta)) {
                $missing = $this->findMissingChunks($manifest, $filesMeta);
                if (!empty($missing)) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Incomplete upload. Missing chunks: ' . json_encode($missing),
                    ], 400);
                }
            }

            // Server-side cap: sum actual chunk bytes before assembling so an
            // attacker cannot bypass the advertised limits by calling the API.
            if (count($filesMeta) > self::MAX_FILES_PER_UPLOAD) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Too many files in this upload',
                ], 422);
            }

            $limits = $this->limitsForType($attachmentType);
            $totalUploadBytes = 0;

            foreach ($filesMeta as $fileMeta) {
                $idx = (int) $fileMeta['index'];
                $fileManifest = $manifest['files'][(string) $idx] ?? null;
                if ($fileManifest === null) {
                    continue;
                }
                for ($ci = 0; $ci < (int) $fileManifest['total_chunks']; $ci++) {
                    $chunkPath = $this->chunkDir($uploadId) . '/' . $idx . '_' . $ci;
                    $chunkFullPath = Storage::disk('local')->path($chunkPath);
                    if (is_file($chunkFullPath)) {
                        $totalUploadBytes += (int) filesize($chunkFullPath);
                    }
                }
            }

            if ($totalUploadBytes > $limits['max_bytes']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Upload exceeds the maximum size for ' . $attachmentType,
                ], 413);
            }

            // Assemble files to disk BEFORE opening a transaction, and track
            // every path written so a failure can clean up after itself. Doing
            // IO inside the transaction left orphaned files when the DB rolled
            // back.
            $assembledFiles = [];
            $writtenPaths = [];

            try {
                if (!empty($filesMeta)) {
                    foreach ($filesMeta as $fileMeta) {
                        $fileIndex = (int) $fileMeta['index'];
                        $originalName = $fileMeta['original_name'];
                        $fileManifest = $manifest['files'][(string) $fileIndex] ?? null;

                        if ($fileManifest === null) {
                            throw new \RuntimeException("File index {$fileIndex} not found in manifest");
                        }

                        $totalChunks = $fileManifest['total_chunks'];

                        // Derive the extension from the declared type, never from
                        // the client filename, so .php/.html cannot be stored.
                        $ext = $this->extensionForType($attachmentType);

                        // Generate output filename (matching existing pattern)
                        $filename = Str::uuid()->toString() . '.' . $ext;
                        $outputPath = 'notery/' . $filename;
                        $fullOutputPath = Storage::disk('local')->path($outputPath);

                        // Ensure notery directory exists
                        $noteryDir = dirname($fullOutputPath);
                        if (!is_dir($noteryDir)) {
                            mkdir($noteryDir, 0755, true);
                        }

                        // Register the path before writing so a mid-write
                        // failure still cleans up the partial file.
                        $writtenPaths[] = $fullOutputPath;

                        // Stream-assemble the chunks, encrypting each frame as
                        // it is written, so the attachment is never stored in
                        // plaintext.
                        $writer = new EncryptedFileWriter($fullOutputPath);

                        $totalSize = 0;
                        $header = '';

                        try {
                            for ($chunkIndex = 0; $chunkIndex < $totalChunks; $chunkIndex++) {
                                $chunkPath = $this->chunkDir($uploadId) . '/' . $fileIndex . '_' . $chunkIndex;
                                $chunkFullPath = Storage::disk('local')->path($chunkPath);

                                if (!file_exists($chunkFullPath)) {
                                    throw new \RuntimeException("Chunk file missing during assembly: {$chunkPath}");
                                }

                                $chunkData = file_get_contents($chunkFullPath);
                                if ($chunkData === false) {
                                    throw new \RuntimeException("Failed to read chunk: {$chunkPath}");
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

                        // Detect the MIME type from the plaintext header we
                        // captured while assembling.
                        $mime = $this->detectMimeFromBuffer($header, $attachmentType);

                        if (!$this->mimeMatchesType($mime, $attachmentType)) {
                            throw new UploadValidationException(
                                'File contents do not match the declared type (' . $attachmentType . ')'
                            );
                        }

                        $assembledFiles[] = [
                            'path'  => $outputPath,
                            'mime'  => $mime,
                            'size'  => $totalSize,
                        ];
                    }
                }

                // Only the database work runs inside the transaction.
                $result = \DB::transaction(function () use ($validated, $assembledFiles) {
                    $encryptedContent = Crypt::encryptString($validated['writeup']);

                    [$code, $hashedCode] = $this->allocateUniqueCode();

                    $save = new Save;
                    $save->writeup    = $encryptedContent;
                    $save->code       = $hashedCode;
                    $save->max_views  = $validated['max_views'] ?? 1;
                    $save->views_count = 0;
                    $save->save();

                    // Create SaveImage records
                    foreach ($assembledFiles as $file) {
                        $save->images()->create([
                            'path'         => $file['path'],
                            'image_mime'   => $file['mime'],
                            'size'         => $file['size'],
                            'is_encrypted' => true,
                        ]);
                    }

                    return ['code' => $code];
                });

                // Post-commit side effects. These must not run before the commit,
                // otherwise a rollback loses the chunks while the DB is clean.
                Storage::disk('local')->deleteDirectory('chunks/' . $uploadId);
                Stats::incrementSaves();

                return response()->json([
                    'status' => 'ok',
                    'code'   => $result['code'],
                ]);
            } catch (\Throwable $e) {
                // Remove any output files written before the failure so a
                // rolled-back upload cannot leak storage.
                foreach ($writtenPaths as $path) {
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }

                throw $e;
            }
        } catch (UploadValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], $e->status());
        } catch (\RuntimeException $e) {
            Log::error('Assembly failed', [
                'error' => $e->getMessage(),
                'upload_id' => $request->input('upload_id'),
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        } catch (\Throwable $e) {
            Log::error('Assembly failed (unexpected)', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Internal server error during assembly',
            ], 500);
        }
    }

    // ─── Private helpers ────────────────────────────────────────────

    /**
     * Get the chunk storage directory path for an upload session.
     */
    private function chunkDir(string $uploadId): string
    {
        return 'chunks/' . $uploadId;
    }

    /**
     * Read the manifest.json for an upload session.
     * Returns null if it doesn't exist.
     */
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

    /**
     * Atomically write the manifest.json.
     */
    private function writeManifest(string $uploadId, array $data): void
    {
        $dir       = $this->chunkDir($uploadId);
        $disk      = Storage::disk('local');
        $tmpPath   = $dir . '/manifest.tmp';
        $finalPath = $dir . '/manifest.json';

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $disk->put($tmpPath, $json);
        // Atomic rename
        $fullTmp   = $disk->path($tmpPath);
        $fullFinal = $disk->path($finalPath);
        rename($fullTmp, $fullFinal);
    }

    /**
     * Validate that the first bytes of a chunk match the expected magic bytes
     * for the claimed attachment type. Returns an error message string or null on success.
     */
    private function validateMagicBytes(string $type, string $data): ?string
    {
        if (strlen($data) < 12) {
            return 'Chunk too small to validate file type';
        }

        switch ($type) {
            case 'image':
                // Check multiple image format headers
                $header4 = substr($data, 0, 4);
                $header3 = substr($data, 0, 3);
                // JPEG: FF D8 FF
                if ($header3 === "\xFF\xD8\xFF") return null;
                // PNG: 89 50 4E 47
                if ($header4 === "\x89PNG") return null;
                // GIF: 47 49 46 38
                if (substr($data, 0, 4) === "GIF8") return null;
                // WebP: 52 49 46 46 (RIFF)
                if ($header4 === "RIFF" && substr($data, 8, 4) === "WEBP") return null;
                return 'File does not appear to be a valid image (expected JPEG, PNG, GIF, or WebP header)';

            case 'pdf':
                if (substr($data, 0, 4) !== '%PDF') {
                    return 'File does not start with %PDF — not a valid PDF';
                }
                return null;

            case 'mp4':
                // ISO BMFF: offset 4 should contain 'ftyp'
                // Also check for 'moov' at offset 4 (some variants)
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

    /**
     * Detect MIME type of an assembled file.
     */
    private function detectMimeType(string $fullPath, ?string $attachmentType): string
    {
        // Use PHP's fileinfo extension for best accuracy
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($fullPath);
            if ($detected && $detected !== 'application/octet-stream') {
                return $detected;
            }
        }

        // Fallback to extension-based mapping
        return $this->fallbackExtension($attachmentType);
    }

    /**
     * Map attachment type to a default MIME type.
     */
    private function fallbackExtension(?string $type): string
    {
        return match ($type) {
            'image' => 'image/jpeg',
            'pdf'   => 'application/pdf',
            'mp4'   => 'video/mp4',
            'zip'   => 'application/zip',
            default => 'application/octet-stream',
        };
    }

    /**
     * Find which chunks are missing for each file.
     * Returns an array like: ["file 0: missing chunks [3,5]", ...]
     */
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
     * Generate a random 4-digit numeric code.
     */
    private function generateCode(int $length = 4): string
    {
        $chars = '1234567890';
        $charLength = strlen($chars);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $chars[random_int(0, $charLength - 1)];
        }
        return $randomString;
    }

    /**
     * Allocate a code that is unique across both saves and portal_sessions,
     * with a bounded retry loop so a saturated keyspace cannot hang a worker.
     *
     * @return array{0: string, 1: string} [plain code, sha256 hash]
     */
    private function allocateUniqueCode(int $maxAttempts = 50): array
    {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $code = $this->generateCode();
            $hashedCode = hash('sha256', $code);

            $taken = Save::where('code', $hashedCode)->exists()
                || PortalSession::where('code', $hashedCode)->exists();

            if (!$taken) {
                return [$code, $hashedCode];
            }
        }

        throw new \RuntimeException(
            'Unable to allocate a unique code after ' . $maxAttempts . ' attempts'
        );
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
     * Server-side size and chunk-count limits per attachment type.
     *
     * @return array{max_bytes: int, max_chunks: int}
     */
    private function limitsForType(?string $type): array
    {
        return match ($type) {
            'image' => ['max_bytes' => 100 * 1024 * 1024, 'max_chunks' => 50],
            'pdf'   => ['max_bytes' => 200 * 1024 * 1024, 'max_chunks' => 200],
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
     * Detect a MIME type from an in-memory header (used when the assembled
     * file is encrypted on disk and cannot be inspected with finfo_file).
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
}
