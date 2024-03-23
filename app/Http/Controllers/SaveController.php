<?php

namespace App\Http\Controllers;

use App\Models\Save;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class SaveController extends Controller
{
    function saveFunction(Request $request) {

        // This function generate a unique 4 digits code
        function generateRandomString($length = 5){
            $a = '1234567890';
            $characterLength = strlen($a);
            $randomString = '';
            for($i = 0; $i < $length; $i++){
                $randomString .=$a[rand(0, $characterLength - 1)];
            }
            return $randomString;
        }
        $code = generateRandomString();

        // Validate the submitted write up
        $validateData = $request->validate([
            'writeup' => 'required|max:2000|string',
        ]);

        $encryptedContent = Crypt::encryptString($validateData['writeup']);

        $contentdata = new Save;
        $contentdata->writeup = $encryptedContent;
        $contentdata->code = $code;
        $contentdata->save();

        // create session
        $request->session()->put('code', $code);

        return redirect("/")->with('success', 'Write up saved successfully');

    }

    public function findFromUrl($code) {

        $encryptedData = Save::where('code', $code)->first();

        if ($encryptedData) {
            // If the write-up exists, display it

            $decryptedText = Crypt::decryptString($encryptedData->writeup);

            return view('show', compact('decryptedText'));
        } else {
            // If the write-up does not exist, display an error message
            $errorMessage = 'The note does not exist.';
            return view('error', compact('errorMessage'));

        }

    }


    public function findWriteupFunction(Request $request){
        // Validate the form input
        $request->validate([
            'code' => 'required',
        ]);

        $code = $request->input('code');

        // Retrieve the write-up associated with the provided code
        $encryptedData = Save::where('code', $code)->first();

        if ($encryptedData) {
            // If the write-up exists, display it
            $decryptedText = Crypt::decryptString($encryptedData->writeup);
            return view('show', compact('decryptedText'));
        } else {
            // If the write-up does not exist, display an error message
            $errorMessage = 'Invalid code. note does not exist.';
            return view('error', compact('errorMessage'));
        }
    }










}
