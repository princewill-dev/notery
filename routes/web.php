<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SaveController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\PortalController;



//this controls the homepage
Route::get('/', [SaveController::class, 'home']);

//this save a submitted writeup
Route::post('/save', [SaveController::class, 'saveFunction']);

// download attachments (signed)
Route::get('/attachments/{id}', [AttachmentController::class, 'download'])
    ->name('attachments.download')
    ->middleware('signed');

// chunked file upload endpoints
Route::post('/upload/chunk', [UploadController::class, 'storeChunk']);
Route::post('/upload/assemble', [UploadController::class, 'assemble']);

// Portal routes
Route::post('/portal/create', [PortalController::class, 'create']);
Route::get('/p/{code}', [PortalController::class, 'show'])
    ->where('code', '[0-9]{4}');
Route::get('/portal/{code}/poll', [PortalController::class, 'poll'])
    ->where('code', '[0-9]{4}');
Route::post('/portal/{code}/message', [PortalController::class, 'sendMessage'])
    ->where('code', '[0-9]{4}');
Route::post('/portal/{code}/upload-chunk', [PortalController::class, 'storeChunk'])
    ->where('code', '[0-9]{4}');
Route::post('/portal/{code}/upload-assemble', [PortalController::class, 'assemble'])
    ->where('code', '[0-9]{4}');
Route::post('/portal/{code}/close', [PortalController::class, 'close'])
    ->where('code', '[0-9]{4}');
Route::get('/portal/{code}/attachment/{messageId}', [PortalController::class, 'downloadAttachment'])
    ->name('portal.attachment')
    ->middleware('signed')
    ->where('code', '[0-9]{4}');
Route::get('/portal/{code}/download/{messageId}', [PortalController::class, 'forceDownload'])
    ->name('portal.download')
    ->middleware('signed')
    ->where('code', '[0-9]{4}');

//this shows a writeup when a unique code is provided as the first path segment
Route::get('/{code}', [SaveController::class, 'findWriteup'])
    ->where('code', '[0-9]{4}');
