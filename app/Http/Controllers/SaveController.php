<?php

namespace App\Http\Controllers;

use App\Models\Save;
use App\Models\Stats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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

        // Validate the submitted data: write-up required, optional attachment and optional max_views
        try {
            $validator = Validator::make($request->all(), [
                'writeup' => 'required|string',
                'attachment_type' => 'nullable|in:image,pdf,mp4,zip|required_with:attachment',
                'attachment' => 'nullable|array|required_with:attachment_type',
                'attachment.*' => 'file',
                'max_views' => 'nullable|integer|min:1|max:100',
            ]);

            $validator->sometimes('attachment.*', 'image|max:102400', function ($input) {
                return $input->attachment_type === 'image';
            });
            $validator->sometimes('attachment.*', 'mimes:pdf|max:204800', function ($input) {
                return $input->attachment_type === 'pdf';
            });
            $validator->sometimes('attachment.*', 'mimes:mp4|max:512000', function ($input) {
                return $input->attachment_type === 'mp4';
            });
            $validator->sometimes('attachment.*', 'mimes:zip|max:512000', function ($input) {
                return $input->attachment_type === 'zip';
            });

            $validateData = $validator->validate();
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
        $contentdata->max_views = $validateData['max_views'] ?? 1;
        $contentdata->views_count = 0;
        // Save the note
        $contentdata->save();

        if ($request->hasFile('attachment')) {
            foreach ($request->file('attachment') as $attachment) {
                if ($attachment && $attachment->isValid()) {
                    $mime = $attachment->getMimeType();
                    $filename = Str::uuid()->toString() . '.' . $attachment->getClientOriginalExtension();
                    $path = $attachment->storeAs('notery', $filename);
                    $contentdata->images()->create([
                        'path' => $path,
                        'image_mime' => $mime,
                        'size' => $attachment->getSize(),
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
            // Use database transaction and locking to prevent race conditions
            return \DB::transaction(function () use ($encryptedData) {
                // Lock the row for update to prevent concurrent access
                $lockedData = Save::where('id', $encryptedData->id)
                    ->lockForUpdate()
                    ->with('images')
                    ->first();
                
                // Double-check it still exists (could have been deleted by another request)
                if (!$lockedData) {
                    $errorMessage = 'Invalid code';
                    session()->flash('errorMessage', $errorMessage);
                    return view('error', compact('errorMessage'));
                }

                $maxViews = $lockedData->max_views;
                $currentViews = $lockedData->views_count ?? 0;

                // Check if already exceeded BEFORE incrementing
                if ($maxViews !== null && $currentViews >= $maxViews) {
                    // Already at or over limit - delete and show error
                    foreach ($lockedData->images as $img) {
                        if (!empty($img->path) && Storage::exists($img->path)) {
                            Storage::delete($img->path);
                        }
                        $img->delete();
                    }
                    $lockedData->delete();
                    
                    $errorMessage = 'This note has reached its view limit and is no longer available';
                    session()->flash('errorMessage', $errorMessage);
                    return view('error', compact('errorMessage'));
                }

                // Atomically increment the view count in the database
                Save::where('id', $lockedData->id)->increment('views_count');
                $newViewCount = $currentViews + 1;

                // Decrypt and prepare the content
                try {
                    $decryptedText = Crypt::decryptString($lockedData->writeup);
                } catch (\Exception $e) {
                    $errorMessage = 'Invalid code';
                    session()->flash('errorMessage', $errorMessage);
                    return view('error', compact('errorMessage'));
                }

                // Prepare attachments as signed download URLs (from DB)
                $attachments = [];
                foreach ($lockedData->images as $img) {
                    $url = URL::temporarySignedRoute('attachments.download', now()->addMinutes(10), ['id' => $img->id]);
                    $attachments[] = [
                        'url' => $url,
                        'mime' => $img->image_mime,
                        'size' => $img->size,
                    ];
                }
                
                // Legacy single-image fallback: make a SaveImage row so we can stream via controller
                if (empty($attachments) && !empty($lockedData->image) && !empty($lockedData->image_mime)) {
                    $legacy = $lockedData->images()->create([
                        'image' => $lockedData->image, // keep existing encrypted blob
                        'image_mime' => $lockedData->image_mime,
                        'path' => null,
                        'size' => null,
                    ]);
                    $url = URL::temporarySignedRoute('attachments.download', now()->addMinutes(10), ['id' => $legacy->id]);
                    $attachments[] = [
                        'url' => $url,
                        'mime' => $lockedData->image_mime,
                        'size' => null,
                    ];
                }

                $remainingViews = null;
                if ($maxViews !== null) {
                    $remainingViews = max(0, (int) $maxViews - (int) $newViewCount);
                }

                // Increment the decodes counter for every successful view
                Stats::incrementDecodes();

                // Render the note before potentially deleting it
                $response = view('show', compact('decryptedText', 'attachments', 'remainingViews', 'maxViews'));

                // Delete if this was the final view
                if ($maxViews !== null && $newViewCount >= $maxViews) {
                    // Delete attachments from storage
                    foreach ($lockedData->images as $img) {
                        if (!empty($img->path) && Storage::exists($img->path)) {
                            Storage::delete($img->path);
                        }
                        $img->delete();
                    }
                    // Delete the save record itself
                    $lockedData->delete();
                }

                return $response;
            });
        } else {
            $errorMessage = 'Invalid code';
            $request->session()->flash('errorMessage', $errorMessage);
            return view('error', compact('errorMessage'));
        }
    }
    
    


}
