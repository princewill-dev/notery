<?php

namespace App\Http\Controllers;

use App\Models\Save;
use Illuminate\Http\Request;

class PageController extends Controller
{
    public function home() {
        return view('home');
    }

}
