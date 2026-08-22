<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One seat in one match.
 *
 * The `slot` is what the game server addresses — it seats *slots*, not users, which is what
 * lets a dropped player reconnect into the hero already standing on the map.
 */
class MobaMatchPlayer extends Model
{
    protected $fillable = [
        'moba_match_id', 'user_id', 'team', 'slot', 'hero',
        'kills', 'deaths', 'assists', 'gold', 'damage', 'mmr_change',
    ];

    protected function casts(): array
    {
        return ['team' => 'integer', 'slot' => 'integer'];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(MobaMatch::class, 'moba_match_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
