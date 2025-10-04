<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SaveController;
use App\Http\Controllers\AttachmentController;



//this controls the homepage
Route::get('/', [SaveController::class, 'home']);

//this save a submitted writeup
Route::post('/save', [SaveController::class, 'saveFunction']);

// download attachments (signed)
Route::get('/attachments/{id}', [AttachmentController::class, 'download'])
    ->name('attachments.download')
    ->middleware('signed');

//this shows a writeup when a unique code is provided as the first path segment
Route::get('/{code}', [SaveController::class, 'findWriteup'])
    ->where('code', '[0-9]{4}');
