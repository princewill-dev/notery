<?php

namespace App\Http\Controllers;

use App\Models\PortalSession;
use App\Models\Save;
use App\Models\Stats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaveController extends Controller
{
    /**
     * How long a consumed note and its attachments remain downloadable before
     * the cleanup command purges them.
     */
    private const ATTACHMENT_GRACE_MINUTES = 15;

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
        // Allocate a code that is unique across notes and portals, with a hard
        // attempt cap so a saturated keyspace cannot pin a PHP worker.
        try {
            [$code, $hashedCode] = $this->allocateUniqueCode();
        } catch (\RuntimeException $e) {
            Log::error('Code allocation failed on save', ['error' => $e->getMessage()]);
            throw ValidationException::withMessages([
                'writeup' => 'We could not allocate a code right now. Please try again in a moment.',
            ]);
        }

        // Validate the submitted data: write-up required, optional attachment and optional max_views
        try {
            $validator = Validator::make($request->all(), [
                'writeup' => 'required|string',
                'attachment_type' => 'nullable|in:image,pdf,mp4,zip|required_with:attachment',
                'attachment' => 'nullable|array|max:20|required_with:attachment_type',
                'attachment.*' => 'file',
                'max_views' => 'nullable|integer|min:1|max:100',
            ]);

            $validator->sometimes('attachment.*', 'mimes:jpg,jpeg,png,gif,webp|max:102400', function ($input) {
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

        // Check if this is a portal code — redirect to portal page
        $portal = PortalSession::where('code', $hashedCode)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->first();
        if ($portal) {
            return redirect('/p/' . $code);
        }
    
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

                // A consumed note stays in place during a short grace period so
                // the viewer's signed download links keep working; only purge
                // once that window has passed.
                $graceExpired = $lockedData->delete_after !== null
                    && $lockedData->delete_after->isPast();

                // Legacy rows (consumed before the grace column existed) have no
                // delete_after, so purge them on the next attempt as before.
                $legacyConsumed = $lockedData->delete_after === null
                    && $maxViews !== null
                    && $currentViews >= $maxViews;

                if ($graceExpired || $legacyConsumed) {
                    $this->purgeSave($lockedData);

                    $errorMessage = 'This note has reached its view limit and is no longer available';
                    session()->flash('errorMessage', $errorMessage);
                    return view('error', compact('errorMessage'));
                }

                if ($lockedData->delete_after !== null) {
                    // Consumed but still inside the download grace period.
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

                // Render the note. We deliberately do NOT delete the note or its
                // attachments here: this response contains signed download links
                // that the viewer still needs. Instead mark the note unreachable
                // and let the scheduled cleanup purge it after the grace period.
                $response = view('show', compact('decryptedText', 'attachments', 'remainingViews', 'maxViews'));

                if ($maxViews !== null && $newViewCount >= $maxViews) {
                    $lockedData->delete_after = now()->addMinutes(self::ATTACHMENT_GRACE_MINUTES);
                    $lockedData->save();
                }

                return $response;
            });
        } else {
            $errorMessage = 'Invalid code';
            $request->session()->flash('errorMessage', $errorMessage);
            return view('error', compact('errorMessage'));
        }
    }
    
    



    /**
     * Generate a random numeric code using a CSPRNG.
     */
    private function generateCode(int $length = 4): string
    {
        $chars = '1234567890';
        $max = strlen($chars) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * Allocate a code that is unique across both the saves and portal_sessions
     * tables (they share the same 4-digit namespace), with a bounded retry loop.
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
     * Delete a note's attachment files/rows and then the note itself.
     */
    private function purgeSave(Save $save): void
    {
        foreach ($save->images as $img) {
            if (!empty($img->path) && Storage::exists($img->path)) {
                Storage::delete($img->path);
            }
            $img->delete();
        }

        $save->delete();
    }
}
