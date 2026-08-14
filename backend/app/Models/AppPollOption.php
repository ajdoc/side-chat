<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One choice on a poll. */
class AppPollOption extends Model
{
    protected $fillable = ['poll_id', 'label', 'position'];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(AppPoll::class, 'poll_id');
    }

    /** @return HasMany<AppPollVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(AppPollVote::class, 'option_id');
    }
}
