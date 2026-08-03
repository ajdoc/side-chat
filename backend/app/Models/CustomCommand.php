<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A canned answer a server declared for itself. See the custom_commands migration.
 */
class CustomCommand extends Model
{
    protected $fillable = [
        'server_id', 'name', 'kind', 'description', 'response',
        'required_badge_id', 'cooldown_seconds', 'enabled',
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /** `/name` only. */
    public const SLASH = 'slash';

    /** `!name` only, against the server's configured prefix. */
    public const PREFIX = 'prefix';

    public const BOTH = 'both';

    public const KINDS = [self::SLASH, self::PREFIX, self::BOTH];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function requiredBadge(): BelongsTo
    {
        return $this->belongsTo(Badge::class, 'required_badge_id');
    }

    /**
     * Is this command reachable in the given shape?
     *
     * @param  string  $kind  self::SLASH or self::PREFIX — the shape it was typed in.
     */
    public function answersTo(string $kind): bool
    {
        return $this->kind === self::BOTH || $this->kind === $kind;
    }

    /**
     * @param  Builder<CustomCommand>  $query
     * @return Builder<CustomCommand>
     */
    public function scopeEnabledIn(Builder $query, int $serverId): Builder
    {
        return $query->where('server_id', $serverId)->where('enabled', true);
    }

    /** Bookkeeping, quietly — this must not touch `updated_at`, which means "last edited". */
    public function recordUse(): void
    {
        $this->forceFill(['use_count' => $this->use_count + 1])->saveQuietly();
    }
}
