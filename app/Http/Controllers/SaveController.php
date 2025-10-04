<?php

namespace App\Http\Controllers;

use App\Models\Save;
use App\Models\Stats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use App\Jobs\DeleteSaveJob;

class SaveController extends Controller
{

    public function home(Request $request)
    {
        // If a code query param is provided, and it is a 4-digit number, redirect to /{code}
        $code = $request->query('code');
        if ($code !== null && preg_match('/^\d{4}$/', $code)) {
            return redirect('/' . $code);
        }
        return view('home');
    }

    public function saveFunction(Request $request) {
        // This function generates a unique 4-digit code
        function generateRandomString($length = 4){
            $a = '1234567890';
            $characterLength = strlen($a);
            $randomString = '';
            for($i = 0; $i < $length; $i++){
                $randomString .= $a[rand(0, $characterLength - 1)];
            }
            return $randomString;
        }

        // Ensure the generated code is unique
        do {
            $code = generateRandomString();
            $hashedCode = hash('sha256', $code);
            $existingCode = Save::where('code', $hashedCode)->first();
        } while ($existingCode);

        // Validate the submitted data: write-up required, files optional (images, pdfs, videos)
        try {
            $validateData = $request->validate([
                'writeup' => 'required|string',
                'files' => 'nullable|array',
                'files.*' => 'file|max:102400|mimetypes:image/*,application/pdf,video/*', // 100MB per file
                // Backward compatibility with "images" field
                'images' => 'nullable|array',
                'images.*' => 'file|max:102400|mimetypes:image/*,application/pdf,video/*',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validation failed on save', [
                'errors' => $e->errors(),
                'content_length' => $request->header('Content-Length'),
            ]);
            throw $e;
        }
    
        $encryptedContent = Crypt::encryptString($validateData['writeup']);
    
        $contentdata = new Save;
        $contentdata->writeup = $encryptedContent;
        $contentdata->code = $hashedCode; // Store the hashed code
        // Save the note
        $contentdata->save();

        // Save any uploaded files (images/pdfs/videos)
        $uploadedFiles = [];
        if ($request->hasFile('files')) {
            $uploadedFiles = $request->file('files');
        } elseif ($request->hasFile('images')) { // backward compatibility
            $uploadedFiles = $request->file('images');
        }

        foreach ($uploadedFiles as $file) {
            if ($file && $file->isValid()) {
                $mime = $file->getMimeType();
                $raw = file_get_contents($file->getRealPath());
                // Encrypt raw bytes directly (avoid base64 to save memory and size)
                $encrypted = Crypt::encryptString($raw);
                $path = 'notery/' . Str::uuid()->toString() . '.enc';
                Storage::put($path, $encrypted);
                $contentdata->images()->create([
                    'path' => $path,
                    'image_mime' => $mime,
                    'size' => $file->getSize(),
                ]);
            }
        }
        
        // Increment the saves counter
        Stats::incrementSaves();
    
        return redirect('/')
            ->with('saved', true)
            ->with('code', $code);
    }
    

    public function findWriteup(Request $request, $code = null) {
        if ($code === null) {
            // Validate the form input
            $request->validate([
                'code' => 'required|string',
            ]);
            $code = $request->input('code');
        }
        
        $hashedCode = hash('sha256', $code); // Hash the input code for lookup
    
        // Retrieve the write-up associated with the provided code
        $encryptedData = Save::where('code', $hashedCode)->with('images')->first();

        if ($encryptedData) {
            // If the write-up exists, display it
            try {
                $decryptedText = Crypt::decryptString($encryptedData->writeup);
                // Prepare attachments as signed download URLs (from DB)
                $attachments = [];
                foreach ($encryptedData->images as $img) {
                    $url = URL::temporarySignedRoute('attachments.download', now()->addMinutes(10), ['id' => $img->id]);
                    $attachments[] = [
                        'url' => $url,
                        'mime' => $img->image_mime,
                        'size' => $img->size,
                    ];
                }
                // Legacy single-image fallback: make a SaveImage row so we can stream via controller
                if (empty($attachments) && !empty($encryptedData->image) && !empty($encryptedData->image_mime)) {
                    $legacy = $encryptedData->images()->create([
                        'image' => $encryptedData->image, // keep existing encrypted blob
                        'image_mime' => $encryptedData->image_mime,
                        'path' => null,
                        'size' => null,
                    ]);
                    $url = URL::temporarySignedRoute('attachments.download', now()->addMinutes(10), ['id' => $legacy->id]);
                    $attachments[] = [
                        'url' => $url,
                        'mime' => $encryptedData->image_mime,
                        'size' => null,
                    ];
                }

                // Schedule deletion after 10 minutes instead of immediate delete
                DeleteSaveJob::dispatch($encryptedData->id)->delay(now()->addMinutes(10));
                
                // Increment the decodes counter
                Stats::incrementDecodes();
                
                return view('show', compact('decryptedText', 'attachments'));
            } catch (\Exception $e) {
                $errorMessage = 'Invalid code';
                $request->session()->flash('errorMessage', $errorMessage);
                return view('error', compact('errorMessage'));
            }
        } else {
            $errorMessage = 'Invalid code';
            $request->session()->flash('errorMessage', $errorMessage);
            return view('error', compact('errorMessage'));
        }
    }
    
    


}
