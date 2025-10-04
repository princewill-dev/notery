<?php

namespace App\Http\Controllers;

use App\Models\SaveImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\MimeTypes;

class AttachmentController extends Controller
{
    public function download(Request $request, int $id)
    {
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

        // Prefer disk path if present
        if (!empty($img->path) && Storage::exists($img->path)) {
            $encrypted = Storage::get($img->path);
            // New format: encrypted raw bytes
            $raw = Crypt::decryptString($encrypted);
        } else {
            // Legacy DB blob fallback
            $base64 = Crypt::decryptString($img->image);
            $raw = base64_decode($base64, true) ?: '';
        }

        return Response::make($raw, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => (string)strlen($raw),
        ]);
    }
}

