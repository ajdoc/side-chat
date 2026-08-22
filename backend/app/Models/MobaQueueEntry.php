<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone waiting for a match.
 *
 * One row per person — the unique index says so — because being in two queues at once is how a
 * player ends up seated in two matches and present in neither.
 */
class MobaQueueEntry extends Model
{
    protected $fillable = ['user_id', 'channel_id', 'team_size', 'hero', 'mmr'];

    protected function casts(): array
    {
        return ['team_size' => 'integer', 'mmr' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
