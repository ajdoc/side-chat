<?php

namespace App\Console\Commands;

use App\Models\BotAuditLog;
use Illuminate\Console\Command;

/**
 * Drops audit lines older than the retention window.
 *
 * The audit log is written on *every* action of every rule, successes included — which is
 * what makes it useful for "why did nothing happen?" and also what makes it the fastest
 * growing table in the app. It is explicitly not a permanent record (see the migration);
 * nothing anybody is entitled to keep should live only here.
 *
 * Deleted in chunks rather than one statement: a single unbounded DELETE over a large table
 * takes a lock for as long as it takes, and this runs on a live database.
 */
class PruneBotAuditLog extends Command
{
    protected $signature = 'bot:prune-audit-log {--days=30 : How much history to keep.}';

    protected $description = 'Delete bot audit lines older than the retention window.';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) $this->option('days'));
        $deleted = 0;

        do {
            $chunk = BotAuditLog::where('created_at', '<', $cutoff)->limit(1000)->delete();
            $deleted += $chunk;
        } while ($chunk > 0);

        $this->info("Pruned {$deleted} audit line(s).");

        return self::SUCCESS;
    }
}
