<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person, in one giveaway. A winner is an entry with `won` set — see the migration.
 */
class GiveawayEntry extends Model
{
    protected $fillable = ['giveaway_id', 'user_id', 'won'];

    protected function casts(): array
    {
        return ['won' => 'boolean'];
    }

    public function giveaway(): BelongsTo
    {
        return $this->belongsTo(Giveaway::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
