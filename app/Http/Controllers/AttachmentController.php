<?php

namespace App\Http\Controllers;

use App\Models\SaveImage;
use App\Support\EncryptedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class AttachmentController extends Controller
{
    public function download(Request $request, int $id)
    {
        try {
            $img = SaveImage::find($id);
            
            // Handle case where attachment was deleted (view limit reached or expired)
            if (!$img) {
                return response()->view('error', [
                    'errorMessage' => 'This attachment is no longer available. It may have expired or reached its view limit.'
                ], 404);
            }

        $mime = $img->image_mime ?: 'application/octet-stream';
        // Determine file extension from stored path so we preserve the original type
        $ext = 'bin';
        if (!empty($img->path)) {
            $pathExt = pathinfo($img->path, PATHINFO_EXTENSION);
            if (is_string($pathExt) && $pathExt !== '') {
                $ext = strtolower($pathExt);
            }
        }
        if ($ext === 'bin') {
            $fallback = [
                'video/mp4' => 'mp4',
                'application/pdf' => 'pdf',
                'application/zip' => 'zip',
                'application/x-zip-compressed' => 'zip',
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
            ];
            if (isset($fallback[$mime])) {
                $ext = $fallback[$mime];
            }
        }
        // Randomize filename as numeric string
        $rand = (string) (random_int(100000, 999999)
            . random_int(100000, 999999)
            . random_int(100000, 999999));
        $filename = $rand . '.' . $ext;

        // Handle both encrypted and unencrypted files
        if (!empty($img->path)) {
            if (!Storage::exists($img->path)) {
                Log::error('Attachment file not found', [
                    'id' => $id,
                    'path' => $img->path,
                    'is_encrypted' => $img->is_encrypted,
                    'storage_path' => Storage::path($img->path),
                ]);
                abort(404, 'File not found');
            }
            
            if ($img->is_encrypted) {
                $fullPath = Storage::path($img->path);

                // New streaming format: decrypt to a temp file and stream it.
                if (EncryptedFile::isEncrypted($fullPath)) {
                    $tmp = EncryptedFile::decryptToTemp($fullPath);

                    return response()->file($tmp, [
                        'Content-Type' => $mime,
                        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                        'X-Content-Type-Options' => 'nosniff',
                    ])->deleteFileAfterSend(true);
                }

                // Legacy format: Crypt::encryptString() output stored on disk.
                $encrypted = Storage::get($img->path);
                $raw = Crypt::decryptString($encrypted);
                return Response::make($raw, 200, [
                    'Content-Type' => $mime,
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    'Content-Length' => (string)strlen($raw),
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            } else {
                // Unencrypted files: use response()->file() for better streaming
                $fullPath = Storage::path($img->path);
                if (!file_exists($fullPath)) {
                    Log::error('Physical file missing', ['path' => $fullPath]);
                    abort(404, 'Physical file not found');
                }
                
                return response()->file($fullPath, [
                    'Content-Type' => $mime,
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        } else {
            // Legacy DB blob fallback (always encrypted).
            if (empty($img->image)) {
                abort(404, 'File not found');
            }

            $base64 = Crypt::decryptString($img->image);
            $raw = base64_decode($base64, true);
            if ($raw === false) {
                abort(500, 'Stored attachment is corrupt');
            }

            return Response::make($raw, 200, [
                'Content-Type' => $mime,
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Content-Length' => (string)strlen($raw),
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }
        } catch (\Throwable $e) {
            // Preserve framework HTTP exceptions (abort(404) etc.) so missing
            // files do not get masked as a 500.
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $e;
            }

            Log::error('Attachment download failed', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            abort(500, 'Download failed');
        }
    }
}

