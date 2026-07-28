<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reaction to a side chat *post* — the forum-list 👍, as opposed to {@link Reaction},
 * which reacts to one message inside a timeline. Same shape on purpose; see the migration
 * for why they aren't one polymorphic table.
 */
class SideChatReaction extends Model
{
    protected $fillable = ['side_chat_id', 'user_id', 'emoji'];

    public function sideChat(): BelongsTo
    {
        return $this->belongsTo(SideChat::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
