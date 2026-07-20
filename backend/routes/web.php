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

/*
 * Side Space documents — the Docs app's files. Same private-disk + signed-URL model as
 * attachments, so a PDF opens straight in an <iframe> and a sheet viewer can fetch its bytes.
 */
Route::get('/space-documents/{document}', [App\Http\Controllers\SpaceDocumentFileController::class, 'show'])
    ->name('space-documents.show')
    ->middleware('signed');

Route::get('/space-documents/{document}/download', [App\Http\Controllers\SpaceDocumentFileController::class, 'download'])
    ->name('space-documents.download')
    ->middleware('signed');
