<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A comment on something an app owns — a tracker task today, a kanban card tomorrow.
 *
 * Not {@see Comment}, which is the reaction-shaped comment on a timeline message. See the
 * migration for why the two stayed apart.
 */
class AppComment extends Model
{
    protected $fillable = ['commentable_type', 'commentable_id', 'channel_id', 'user_id', 'body', 'edited_at'];

    protected function casts(): array
    {
        return ['edited_at' => 'datetime'];
    }

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * The private stream this comment travels on — the channel's own, the same one its
     * messages, board and calendar already use. Sharing that stream is what makes a comment
     * typed in a floating window appear in the tab behind it without any extra plumbing.
     */
    public function streamName(): string
    {
        return 'channel.'.$this->channel_id;
    }
}
