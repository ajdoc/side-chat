<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A comment on a side chat *post* — the forum-list version of {@link Comment}, which
 * annotates one message inside a timeline. Same shape and the same normalisation (it
 * reuses `Comment::normalize`, so a phrase groups identically wherever it's left).
 */
class SideChatComment extends Model
{
    protected $fillable = ['side_chat_id', 'user_id', 'body', 'body_key', 'emoji'];

    public function sideChat(): BelongsTo
    {
        return $this->belongsTo(SideChat::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
