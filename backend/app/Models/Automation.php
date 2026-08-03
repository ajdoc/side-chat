<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One "when X, do Y" rule. See the automations migration for why the shape is this shape.
 */
class Automation extends Model
{
    protected $fillable = [
        'server_id', 'name', 'trigger', 'trigger_config', 'conditions', 'enabled', 'builtin',
    ];

    protected function casts(): array
    {
        return [
            'trigger_config' => 'array',
            'conditions' => 'array',
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * The features that own a row rather than appearing in the generic list.
     *
     * A built-in fires through the same engine as everything else — the name only says
     * which dashboard page edits it.
     */
    public const BUILTIN_WELCOME = 'welcome';

    public const BUILTIN_REACTION_ROLE = 'reaction_role';

    public const BUILTIN_GIVEAWAY = 'giveaway';

    /** @return HasMany<AutomationAction> */
    public function actions(): HasMany
    {
        return $this->hasMany(AutomationAction::class)->orderBy('position')->orderBy('id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return HasMany<BotAuditLog> */
    public function runs(): HasMany
    {
        return $this->hasMany(BotAuditLog::class)->latest();
    }

    /**
     * Everything that should be considered when `$trigger` fires in `$server`.
     *
     * @param  Builder<Automation>  $query
     * @return Builder<Automation>
     */
    public function scopeListeningFor(Builder $query, int $serverId, string $trigger): Builder
    {
        return $query->where('server_id', $serverId)
            ->where('trigger', $trigger)
            ->where('enabled', true);
    }

    /** One narrowing value off `trigger_config`, e.g. the channel a message must be in. */
    public function triggerOption(string $key, mixed $default = null): mixed
    {
        return $this->trigger_config[$key] ?? $default;
    }

    /**
     * Note that this rule ran.
     *
     * `saveQuietly` and a raw increment: this is bookkeeping, and it should not touch
     * `updated_at` (which the dashboard shows as "last edited") or fire model events that
     * could, in turn, trigger something.
     */
    public function recordRun(): void
    {
        $this->forceFill([
            'run_count' => $this->run_count + 1,
            'last_run_at' => now(),
        ])->saveQuietly();
    }
}
