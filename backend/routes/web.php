<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Attachment files live on a private disk and are served through short-lived signed
 * URLs, so <img> tags and in-browser PDF viewing work without an auth header.
 */
Route::get('/attachments/{attachment}', [App\Http\Controllers\AttachmentController::class, 'show'])
    ->name('attachments.show')
    ->middleware('signed');

Route::get('/attachments/{attachment}/download', [App\Http\Controllers\AttachmentController::class, 'download'])
    ->name('attachments.download')
    ->middleware('signed');
