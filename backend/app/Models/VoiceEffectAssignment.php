<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What this channel's call plays for one particular person, overriding the room's default.
 *
 * A row with both effects null is not a thing worth keeping — that's just "nothing special
 * about this person", which is the absence of a row. The action deletes it instead of
 * storing it, so the table only ever holds decisions somebody actually made.
 */
class VoiceEffectAssignment extends Model
{
    protected $fillable = ['channel_id', 'user_id', 'join_effect', 'leave_effect'];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
