<?php

namespace App\Http\Controllers;

use App\Models\Save;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class SaveController extends Controller
{
    public function saveFunction(Request $request) {
        // This function generates a unique 4-digit code
        function generateRandomString($length = 5){
            $a = '1234567890';
            $characterLength = strlen($a);
            $randomString = '';
            for($i = 0; $i < $length; $i++){
                $randomString .= $a[rand(0, $characterLength - 1)];
            }
            return $randomString;
        }
        $code = generateRandomString();
    
        // Validate the submitted write-up
        $validateData = $request->validate([
            'writeup' => 'required|max:2000|string',
        ]);
    
        $encryptedContent = Crypt::encryptString($validateData['writeup']);
        $hashedCode = hash('sha256', $code); // Hash the code
    
        $contentdata = new Save;
        $contentdata->writeup = $encryptedContent;
        $contentdata->code = $hashedCode; // Store the hashed code
        $contentdata->save();
    
        return view('code', compact('code'));
    }
    

    public function findFromUrl($code) {
        
        $hashedCode = hash('sha256', $code); // Hash the input code for lookup
    
        // Retrieve the write-up associated with the provided code
        $encryptedData = Save::where('code', $hashedCode)->first();
    
        if ($encryptedData) {
            // If the write-up exists, display it
            try {
                $decryptedText = Crypt::decryptString($encryptedData->writeup);
                return view('show', compact('decryptedText'));
            } catch (\Exception $e) {
                $errorMessage = 'Decryption of the write-up failed.';
                return view('find', compact('errorMessage'));
            }
        } else {
            $errorMessage = 'Invalid code. The note does not exist.';
            return view('find', compact('errorMessage'));
        }

    }

    
    public function findWriteupFunction(Request $request){
        // Validate the form input
        $request->validate([
            'code' => 'required|string',
        ]);
    
        $code = $request->input('code');
        $hashedCode = hash('sha256', $code); // Hash the input code for lookup
    
        // Retrieve the write-up associated with the provided code
        $encryptedData = Save::where('code', $hashedCode)->first();
    
        if ($encryptedData) {
            // If the write-up exists, display it
            try {
                $decryptedText = Crypt::decryptString($encryptedData->writeup);
                return view('show', compact('decryptedText'));
            } catch (\Exception $e) {
                $errorMessage = 'Decryption of the write-up failed.';
                return view('find', compact('errorMessage'));
            }
        } else {
            $errorMessage = 'Invalid code. The note does not exist.';
            return view('find', compact('errorMessage'));
        }
    }
    
    


}
