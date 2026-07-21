<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ServesStoredFiles;
use App\Models\SpaceDocument;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves a Side Space document's bytes over a short-lived signed URL — the same pattern
 * {@see AttachmentController} uses. No auth header is needed (the signature is the grant), so
 * a PDF opens straight in an <iframe> and a viewer can fetch a sheet's bytes.
 */
class SpaceDocumentFileController extends Controller
{
    use ServesStoredFiles;

    /** Inline — a PDF renders in the browser; other kinds are fetched by their viewer. */
    public function show(SpaceDocument $document): BinaryFileResponse
    {
        return response()->file($this->storedFilePath($document->disk, $document->path), [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($document->name).'"',
        ]);
    }

    /** Forced download. */
    public function download(SpaceDocument $document): BinaryFileResponse
    {
        return response()->download($this->storedFilePath($document->disk, $document->path), $document->name);
    }
}
