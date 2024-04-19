<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PageController;
use App\Http\Controllers\SaveController;
//use App\Http\Controllers\externalController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/


//this controls the homepage
Route::get('/', [PageController::class, 'home']); 

//this save a submitted writeup
Route::post('/savewriteup', [SaveController::class, 'saveFunction']); 

//this help to find a write when the unique code is appended on the url eg: savewriteup.com/1234
// Route::get('/{code}', [SaveController::class, 'findFromUrl']); 

//this shows the find-writeup page where someone can enter a unique code
Route::get('/find', [PageController::class, 'findFunction']);

//this redirects to the show-writeup page when clicked from the success page after saving writeup
Route::get('/find/{code}', [SaveController::class, 'findFromUrl']);

//this shows a writeup when a unique code is submitted from the find page
Route::post('/findwriteupform', [SaveController::class, 'findWriteupFunction']);


//Route::post('/findwriteup/{code}', [SaveController::class, 'findWriteup']);

// Route::get('/task-app', [externalController::class, 'externalFunction']);
