<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's admission to a meeting — the audit behind a link that strangers can follow.
 *
 * A record of *admission*, not of attendance: it is stamped the first time somebody is let in,
 * and rejoining after a dropped connection is not a second event. Attendance is the call's own
 * roster, which is live and doesn't outlive the call; this is the part that has to.
 */
class MeetingJoin extends Model
{
    protected $fillable = ['meeting_id', 'user_id', 'via', 'external', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return ['external' => 'boolean'];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
