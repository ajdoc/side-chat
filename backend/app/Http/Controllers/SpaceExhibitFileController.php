<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ServesStoredFiles;
use App\Models\SideSpaceExhibit;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves an exhibit's picture over a short-lived signed URL — the same pattern
 * {@see AttachmentController} uses, so an `<img>` needs no auth header.
 *
 * Inline only. There is no download here on purpose: a museum is somewhere you look at things,
 * and a "save this" button on somebody else's uploaded artwork is a different feature with
 * different questions attached to it.
 */
class SpaceExhibitFileController extends Controller
{
    use ServesStoredFiles;

    public function show(SideSpaceExhibit $exhibit): BinaryFileResponse|RedirectResponse
    {
        return $this->storedFileResponse(
            $exhibit->disk,
            $exhibit->path,
            $exhibit->title,
            $exhibit->mime_type,
        );
    }
}
