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

        // Validate the submitted data: write-up required, single optional attachment
        try {
            $validator = Validator::make($request->all(), [
                'writeup' => 'required|string',
                'attachment_type' => 'nullable|in:image,pdf,mp4|required_with:attachment',
                'attachment' => 'nullable|array|required_with:attachment_type',
                'attachment.*' => 'file',
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

                // Keep all attachments permanently stored, only delete the encrypted writeup
                $encryptedData->writeup = '';
                $encryptedData->save();
                
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
