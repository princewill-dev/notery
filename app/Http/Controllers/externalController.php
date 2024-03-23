<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class externalController extends Controller
{
    public function externalFunction(){
        
        header('location: ../../../task-app');
        
    }
}
