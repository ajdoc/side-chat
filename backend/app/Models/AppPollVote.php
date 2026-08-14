<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's pick of one option. Uniqueness is the database's job — see the migration. */
class AppPollVote extends Model
{
    protected $fillable = ['poll_id', 'option_id', 'user_id'];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(AppPoll::class, 'poll_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(AppPollOption::class, 'option_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
