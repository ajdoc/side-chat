<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Handing stored bytes to a browser in a way that supports ranges, whichever disk they're on.
 *
 * Range support is not a nicety here. A <video> opens with `bytes=0-` and then re-asks at
 * whatever offset you scrub to; a PDF viewer pulls the trailer first. Answer those with a single
 * whole-file stream and a large clip never reaches its first frame before the request times out,
 * and seeking is impossible because there is nothing to seek with.
 *
 * The two disks get there differently:
 *
 *  - A local disk has a real path, so a *file* response is returned and Symfony does the work —
 *    it reads the `Range` header and answers 206 with just those bytes.
 *  - An object store already speaks ranges, and is far better placed to than we are. So instead
 *    of pulling the object through this application to re-serve it, the caller is redirected to
 *    a short-lived signed URL and fetches it from the bucket directly. That keeps the bytes off
 *    our containers (the same reason uploads go straight there) and, on a zero-egress store,
 *    off the bill.
 *
 * Either way the *route* stays signed and authorized: the redirect is only issued to someone who
 * already got through the check, and the URL it points at expires. A "remote" disk is a reference
 * to someone else's CDN with no bytes of ours behind it, and those URLs are handed out directly,
 * never routed here.
 */
trait ServesStoredFiles
{
    /** How long a signed download URL is good for — one sitting with a file, not a shareable link. */
    private const DOWNLOAD_TTL_MINUTES = 30;

    /**
     * A response serving the stored file, as an inline view or a forced download.
     *
     * `$name` is the filename the browser should use, and `$mime` the type it should trust —
     * both come from our own row rather than from whatever the object store inferred.
     */
    protected function storedFileResponse(
        string $disk,
        string $path,
        string $name,
        string $mime,
        bool $download = false,
    ): BinaryFileResponse|RedirectResponse {
        $driver = config("filesystems.disks.{$disk}.driver");
        $filesystem = Storage::disk($disk);
        $disposition = ($download ? 'attachment' : 'inline').'; filename="'.addslashes($name).'"';

        abort_unless($filesystem->exists($path), 404);

        if ($driver === 'local') {
            return $download
                ? response()->download($filesystem->path($path), $name)
                : response()->file($filesystem->path($path), [
                    'Content-Type' => $mime,
                    'Content-Disposition' => $disposition,
                ]);
        }

        abort_unless($driver === 's3', 404);

        // The response-* overrides ride along inside the signature, so the store returns the file
        // under our name and type rather than the key and whatever it guessed on upload.
        return redirect($filesystem->temporaryUrl($path, now()->addMinutes(self::DOWNLOAD_TTL_MINUTES), [
            'ResponseContentType' => $mime,
            'ResponseContentDisposition' => $disposition,
        ]));
    }

    /**
     * The absolute path of a stored file. Local disks only — an object store has no such path.
     *
     * Prefer {@see storedFileResponse}, which works on either. This remains for the callers that
     * genuinely need a path on disk rather than a response.
     */
    protected function storedFilePath(string $disk, string $path): string
    {
        abort_unless(config("filesystems.disks.{$disk}.driver") === 'local', 404);

        $filesystem = Storage::disk($disk);

        abort_unless($filesystem->exists($path), 404);

        return $filesystem->path($path);
    }
}
