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

        // Validate the submitted data: write-up required, files optional
        // Separate rules: images (encrypted, 100MB total), PDF (200MB), MP4/ZIP (500MB)
        try {
            $validateData = $request->validate([
                'writeup' => 'required|string',
                'images' => 'nullable|array',
                'images.*' => 'image|max:102400', // 100MB per image (collective checked below)
                'files' => 'nullable|array',
                'files.*' => 'file|mimes:pdf,mp4,zip|max:512000', // 500MB max
            ]);
            
            // Validate collective image size (100MB total)
            if ($request->hasFile('images')) {
                $totalImageSize = 0;
                foreach ($request->file('images') as $img) {
                    if ($img && $img->isValid()) {
                        $totalImageSize += $img->getSize();
                    }
                }
                if ($totalImageSize > 100 * 1024 * 1024) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'images' => 'The total size of all images must not exceed 100MB.'
                    ]);
                }
            }
            
            // Validate individual file size limits by type
            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $idx => $file) {
                    if ($file && $file->isValid()) {
                        $mime = $file->getMimeType();
                        $sizeMB = $file->getSize() / (1024 * 1024);
                        if ($mime === 'application/pdf' && $sizeMB > 200) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                "files.{$idx}" => 'PDF files must not exceed 200MB.'
                            ]);
                        }
                        if (in_array($mime, ['video/mp4', 'application/zip']) && $sizeMB > 500) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                "files.{$idx}" => 'MP4 and ZIP files must not exceed 500MB.'
                            ]);
                        }
                    }
                }
            }
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

        // Save encrypted images (text+images are encrypted)
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $imageFile) {
                if ($imageFile && $imageFile->isValid()) {
                    $mime = $imageFile->getMimeType();
                    $raw = file_get_contents($imageFile->getRealPath());
                    $encrypted = Crypt::encryptString($raw);
                    $path = 'notery/' . Str::uuid()->toString() . '.enc';
                    Storage::put($path, $encrypted);
                    $contentdata->images()->create([
                        'path' => $path,
                        'image_mime' => $mime,
                        'size' => $imageFile->getSize(),
                        'is_encrypted' => true,
                    ]);
                }
            }
        }
        
        // Save unencrypted files (PDF, MP4, ZIP) using streaming to avoid memory issues
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                if ($file && $file->isValid()) {
                    $mime = $file->getMimeType();
                    $filename = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();
                    // Use storeAs for streaming large files without loading into memory
                    $path = $file->storeAs('notery', $filename);
                    $contentdata->images()->create([
                        'path' => $path,
                        'image_mime' => $mime,
                        'size' => $file->getSize(),
                        'is_encrypted' => false,
                    ]);
                }
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

                // Delete encrypted content (text + images) immediately
                $hasUnencryptedFiles = $encryptedData->images()->where('is_encrypted', false)->exists();
                
                foreach ($encryptedData->images as $img) {
                    if ($img->is_encrypted && !empty($img->path) && Storage::exists($img->path)) {
                        Storage::delete($img->path);
                    }
                }
                // Delete the encrypted image records
                $encryptedData->images()->where('is_encrypted', true)->delete();
                
                if ($hasUnencryptedFiles) {
                    // Keep Save record but clear writeup, schedule deletion for 5 minutes
                    $encryptedData->writeup = '';
                    $encryptedData->save();
                    DeleteSaveJob::dispatch($encryptedData->id)->delay(now()->addMinutes(5));
                } else {
                    // No unencrypted files, delete everything immediately
                    $encryptedData->delete();
                }
                
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
