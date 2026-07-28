<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ServesStoredFiles;
use App\Models\SpaceDocument;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves a Side Desk document's bytes over a short-lived signed URL — the same pattern
 * {@see AttachmentController} uses. No auth header is needed (the signature is the grant), so
 * a PDF opens straight in an <iframe> and a viewer can fetch a sheet's bytes.
 */
class SpaceDocumentFileController extends Controller
{
    use ServesStoredFiles;

    /** Inline — a PDF renders in the browser; other kinds are fetched by their viewer. */
    public function show(SpaceDocument $document): BinaryFileResponse|RedirectResponse
    {
        return $this->storedFileResponse(
            $document->disk,
            $document->path,
            $document->name,
            $document->mime_type,
        );
    }

    /** Forced download. */
    public function download(SpaceDocument $document): BinaryFileResponse|RedirectResponse
    {
        return $this->storedFileResponse(
            $document->disk,
            $document->path,
            $document->name,
            $document->mime_type,
            download: true,
        );
    }
}
