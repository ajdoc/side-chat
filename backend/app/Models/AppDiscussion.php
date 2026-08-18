<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The side chat that belongs to one app item — a task, a card, a poll, an event.
 *
 * A pointer and nothing else: the conversation lives in the side chat, the work lives in the
 * item, and this says which is about which. See the migration for why it isn't a column on
 * eight tables.
 */
class AppDiscussion extends Model
{
    protected $fillable = ['subject_type', 'subject_id', 'channel_id', 'side_chat_id', 'created_by'];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function sideChat(): BelongsTo
    {
        return $this->belongsTo(SideChat::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
