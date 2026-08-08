<?php

namespace App\Models;

use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * A recurring post. See the bot_schedules migration for why this is a row and not a job.
 */
class BotSchedule extends Model
{
    protected $fillable = [
        'server_id', 'name', 'channel_id', 'extra_channel_ids', 'body', 'cron', 'timezone', 'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'extra_channel_ids' => 'array',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    /**
     * What the UI offers instead of asking anybody to write cron.
     *
     * The column stays a general expression — these are just the shapes people actually
     * ask for, so the common case is a dropdown rather than a syntax lesson.
     */
    public const PRESETS = [
        'hourly' => '0 * * * *',
        'daily' => '0 9 * * *',
        'weekly' => '0 9 * * 1',
        'monthly' => '0 9 1 * *',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Every channel this posts to, in order, with the fallback applied.
     *
     * Ids only — the caller resolves them, and the ones that no longer exist drop out there.
     * `$fallback` is the server's reminder channel, used when the schedule names none of its
     * own; it never applies when the schedule *does* name channels.
     *
     * @return array<int, int>
     */
    public function channelIds(?int $fallback = null): array
    {
        $ids = array_filter([$this->channel_id, ...($this->extra_channel_ids ?? [])]);

        return array_values(array_unique($ids === [] ? array_filter([$fallback]) : $ids));
    }

    /** Is this a cron expression we can actually run? Used to refuse a bad one on save. */
    public static function validCron(string $expression): bool
    {
        return CronExpression::isValidExpression($expression);
    }

    /**
     * When this should next fire, in UTC.
     *
     * Computed in the schedule's own timezone and converted, which is the whole reason the
     * timezone is stored: "every Monday at 9" means nine where the server's people are, and
     * a UTC computation would drift by an hour twice a year for half the world.
     *
     * Returns null for an expression we can't parse. The runner treats that as "never due"
     * rather than throwing — a schedule saved by an older, laxer validation shouldn't be
     * able to stop every other server's schedules from running.
     */
    public function computeNextRun(?Carbon $after = null): ?Carbon
    {
        try {
            $zone = $this->timezone ?: 'UTC';
            $from = ($after ?? now())->copy()->setTimezone($zone);

            $next = (new CronExpression($this->cron))->getNextRunDate($from);

            return Carbon::instance($next)->setTimezone($zone)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /** Move the window forward after a run. */
    public function markRun(): void
    {
        $this->forceFill([
            'last_run_at' => now(),
            'next_run_at' => $this->computeNextRun(),
        ])->saveQuietly();
    }
}
