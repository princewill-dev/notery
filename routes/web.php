<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SaveController;



//this controls the homepage
Route::get('/', [SaveController::class, 'home']);

//this shows the find-writeup page where someone can enter a unique code
Route::get('/find', [SaveController::class, 'findFunction']);

//this save a submitted writeup
Route::post('/save', [SaveController::class, 'saveFunction']);

//this redirects to the show-writeup page when clicked from the success page after saving writeup
Route::get('/find/{code}', [SaveController::class, 'findWriteup']);

//this shows a writeup when a unique code is submitted from the find page
Route::post('/find', [SaveController::class, 'findWriteup']);
