<?php

namespace App\Http\Controllers;

use App\Models\Save;
use App\Models\Stats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class SaveController extends Controller
{

    public function home()
    {
        return view('home'); // Adjust the view name as needed
    }

    public function findFunction() {
        return view('find');
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

        // Validate the submitted write-up
        $validateData = $request->validate([
            'writeup' => 'required|string',
        ]);
    
        $encryptedContent = Crypt::encryptString($validateData['writeup']);
    
        $contentdata = new Save;
        $contentdata->writeup = $encryptedContent;
        $contentdata->code = $hashedCode; // Store the hashed code
        $contentdata->save();
        
        // Increment the saves counter
        Stats::incrementSaves();
    
        return view('code', compact('code'));
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
        $encryptedData = Save::where('code', $hashedCode)->first();
    
        if ($encryptedData) {
            // If the write-up exists, display it
            try {
                $decryptedText = Crypt::decryptString($encryptedData->writeup);
                $encryptedData->delete(); // Delete the record after viewing
                
                // Increment the decodes counter
                Stats::incrementDecodes();
                
                return view('show', compact('decryptedText'));
            } catch (\Exception $e) {
                $errorMessage = 'Invalid code';
                $request->session()->flash('errorMessage', $errorMessage);
                return view('find')->with('error', 'Invalid code');
            }
        } else {
            $errorMessage = 'Invalid code';
            $request->session()->flash('errorMessage', $errorMessage);
            return view('find')->with('error', 'Invalid code');
        }
    }
    
    


}
