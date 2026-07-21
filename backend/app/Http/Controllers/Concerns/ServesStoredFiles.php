<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Serving stored bytes as a *file* response rather than a streamed one, which is what buys
 * range support: Symfony reads the `Range` header and answers 206 with just those bytes.
 *
 * It matters for anything big. A <video> opens with `bytes=0-` and then re-asks at whatever
 * offset you scrub to; a PDF viewer pulls the trailer first. Streaming instead pushes the whole
 * file for each of those — a large clip never reaches the first frame before the request times
 * out, and seeking is impossible because there is nothing to seek with.
 */
trait ServesStoredFiles
{
    /**
     * The absolute path of a stored file, since a range response needs a real file and not a
     * stream. Only local disks have one — a "remote" disk is a reference to someone else's CDN
     * with no bytes of ours behind it, and those URLs are handed out directly, never routed here.
     */
    protected function storedFilePath(string $disk, string $path): string
    {
        abort_unless(config("filesystems.disks.{$disk}.driver") === 'local', 404);

        $filesystem = Storage::disk($disk);

        abort_unless($filesystem->exists($path), 404);

        return $filesystem->path($path);
    }
}
