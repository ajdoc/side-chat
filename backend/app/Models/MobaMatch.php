<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One match: who played, on which side, and how it ended.
 *
 * The row exists from the moment matchmaking forms a roster, through the game server playing it,
 * until a result comes back. It is the API's only handle on something that runs entirely
 * elsewhere — see MOBA.md for why the simulation is not in PHP.
 */
class MobaMatch extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_LIVE = 'live';

    public const STATUS_FINISHED = 'finished';

    /** Formed, but nobody ever connected — or the server holding it went away. */
    public const STATUS_ABANDONED = 'abandoned';

    public const TEAM_BLUE = 0;

    public const TEAM_RED = 1;

    protected $fillable = [
        'channel_id', 'team_size', 'status', 'server_address',
        'winning_team', 'started_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'team_size' => 'integer',
            'winning_team' => 'integer',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return HasMany<MobaMatchPlayer, $this> */
    public function players(): HasMany
    {
        return $this->hasMany(MobaMatchPlayer::class);
    }

    public function isOver(): bool
    {
        return in_array($this->status, [self::STATUS_FINISHED, self::STATUS_ABANDONED], true);
    }
}
