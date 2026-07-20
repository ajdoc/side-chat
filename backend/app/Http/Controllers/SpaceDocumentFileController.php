<?php

namespace App\Http\Controllers;

use App\Models\SpaceDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a Side Space document's bytes over a short-lived signed URL — the same pattern
 * {@see AttachmentController} uses. No auth header is needed (the signature is the grant), so
 * a PDF opens straight in an <iframe> and a viewer can fetch a sheet's bytes.
 */
class SpaceDocumentFileController extends Controller
{
    /** Inline — a PDF renders in the browser; other kinds are fetched by their viewer. */
    public function show(SpaceDocument $document): StreamedResponse
    {
        return Storage::disk($document->disk)->response($document->path, $document->name, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($document->name).'"',
        ]);
    }

    /** Forced download. */
    public function download(SpaceDocument $document): StreamedResponse
    {
        return Storage::disk($document->disk)->download($document->path, $document->name);
    }
}
