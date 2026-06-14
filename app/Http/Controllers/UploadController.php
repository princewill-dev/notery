<?php

namespace App\Http\Controllers;

use App\Models\Save;
use App\Models\SaveImage;
use App\Models\Stats;
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

            // Assemble files within a database transaction
            return \DB::transaction(function () use ($uploadId, $attachmentType, $validated, $manifest, $filesMeta) {
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

                        // Determine extension from original name
                        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                        if ($ext === '' || $ext === 'bin') {
                            $ext = $this->fallbackExtension($attachmentType);
                        }

                        // Generate output filename (matching existing pattern)
                        $filename = Str::uuid()->toString() . '.' . $ext;
                        $outputPath = 'notery/' . $filename;
                        $fullOutputPath = Storage::disk('local')->path($outputPath);

                        // Ensure notery directory exists
                        $noteryDir = dirname($fullOutputPath);
                        if (!is_dir($noteryDir)) {
                            mkdir($noteryDir, 0755, true);
                        }

                        // Stream-assemble chunks into the final file
                        $out = fopen($fullOutputPath, 'wb');
                        if ($out === false) {
                            throw new \RuntimeException("Failed to open output file: {$fullOutputPath}");
                        }

                        $totalSize = 0;
                        for ($chunkIndex = 0; $chunkIndex < $totalChunks; $chunkIndex++) {
                            $chunkPath = $this->chunkDir($uploadId) . '/' . $fileIndex . '_' . $chunkIndex;
                            $chunkFullPath = Storage::disk('local')->path($chunkPath);

                            if (!file_exists($chunkFullPath)) {
                                fclose($out);
                                // Clean up partial output
                                if (file_exists($fullOutputPath)) {
                                    unlink($fullOutputPath);
                                }
                                throw new \RuntimeException("Chunk file missing during assembly: {$chunkPath}");
                            }

                            $chunkData = file_get_contents($chunkFullPath);
                            if ($chunkData === false) {
                                fclose($out);
                                if (file_exists($fullOutputPath)) {
                                    unlink($fullOutputPath);
                                }
                                throw new \RuntimeException("Failed to read chunk: {$chunkPath}");
                            }

                            $written = fwrite($out, $chunkData);
                            if ($written === false) {
                                fclose($out);
                                if (file_exists($fullOutputPath)) {
                                    unlink($fullOutputPath);
                                }
                                throw new \RuntimeException("Failed to write to output file");
                            }
                            $totalSize += strlen($chunkData);
                        }

                        fclose($out);

                        // Detect actual MIME type
                        $mime = $this->detectMimeType($fullOutputPath, $attachmentType);

                        $assembledFiles[] = [
                            'path'  => $outputPath,
                            'mime'  => $mime,
                            'size'  => $totalSize,
                        ];
                    }
                }

                // Create the Save record (reusing existing pattern from saveFunction)
                $encryptedContent = Crypt::encryptString($validated['writeup']);

                $save = new Save;
                $save->writeup    = $encryptedContent;
                $save->code       = hash('sha256', 'temp'); // placeholder — will use the code from the frontend
                $save->max_views  = $validated['max_views'] ?? 1;
                $save->views_count = 0;
                // Actually, we need a unique code. Generate it here so the old flow works.
                // Reuse the same code generation from SaveController.
                do {
                    $code = $this->generateCode();
                    $hashedCode = hash('sha256', $code);
                } while (Save::where('code', $hashedCode)->exists());

                $save->code = $hashedCode;
                $save->save();

                // Create SaveImage records
                foreach ($assembledFiles as $file) {
                    $save->images()->create([
                        'path'         => $file['path'],
                        'image_mime'   => $file['mime'],
                        'size'         => $file['size'],
                        'is_encrypted' => false,
                    ]);
                }

                // Delete the chunk directory now that assembly is complete
                Storage::disk('local')->deleteDirectory('chunks/' . $uploadId);

                // Increment global stats
                Stats::incrementSaves();

                return response()->json([
                    'status' => 'ok',
                    'code'   => $code,
                ]);
            });
        } catch (\RuntimeException $e) {
            Log::error('Assembly failed', [
                'error' => $e->getMessage(),
                'upload_id' => $request->input('upload_id'),
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
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
}
