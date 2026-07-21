<?php

namespace App\Console\Commands;

use App\Models\ChunkedUpload;
use Illuminate\Console\Command;

/**
 * Sweep up staged files nobody claimed.
 *
 * A chunked upload is only ever half a transaction: the bytes land first, and a message claims
 * them afterwards. Anything that never gets claimed — a composer closed mid-transfer, a send
 * abandoned, a tab that crashed — leaves a file on disk with no attachment pointing at it. This
 * is the other half of that bargain, without which the staging area grows forever.
 *
 * A day's grace covers the honest cases (a long upload on a slow line, a message drafted and
 * sent later) while still bounding the mess.
 */
class PruneChunkedUploads extends Command
{
    protected $signature = 'uploads:prune {--hours=24 : How old an unclaimed upload must be}';

    protected $description = 'Delete chunked uploads that were never claimed by a message';

    public function handle(): int
    {
        $cutoff = now()->subHours((int) $this->option('hours'));
        $stale = ChunkedUpload::query()->where('created_at', '<', $cutoff)->get();

        foreach ($stale as $upload) {
            $upload->deleteFile();
            $upload->delete();
        }

        $this->info("Pruned {$stale->count()} unclaimed upload(s).");

        return self::SUCCESS;
    }
}
