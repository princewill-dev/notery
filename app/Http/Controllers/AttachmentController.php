<?php

namespace App\Http\Controllers;

use App\Models\SaveImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\MimeTypes;

class AttachmentController extends Controller
{
    public function download(Request $request, int $id)
    {
        try {
            $img = SaveImage::findOrFail($id);

        $mime = $img->image_mime ?: 'application/octet-stream';
        // Determine a suitable file extension from MIME
        $ext = 'bin';
        try {
            if (class_exists(\Symfony\Component\Mime\MimeTypes::class)) {
                $exts = MimeTypes::getDefault()->getExtensions($mime);
                if (!empty($exts)) {
                    $ext = $exts[0];
                }
            }
        } catch (\Throwable $e) {
            // ignore
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
                // Encrypted files: decrypt and serve
                $encrypted = Storage::get($img->path);
                $raw = Crypt::decryptString($encrypted);
                return Response::make($raw, 200, [
                    'Content-Type' => $mime,
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    'Content-Length' => (string)strlen($raw),
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
                ]);
            }
        } else {
            // Legacy DB blob fallback (always encrypted)
            $base64 = Crypt::decryptString($img->image);
            $raw = base64_decode($base64, true) ?: '';
            return Response::make($raw, 200, [
                'Content-Type' => $mime,
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Content-Length' => (string)strlen($raw),
            ]);
        }
        } catch (\Exception $e) {
            Log::error('Attachment download failed', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            abort(500, 'Download failed: ' . $e->getMessage());
        }
    }
}

