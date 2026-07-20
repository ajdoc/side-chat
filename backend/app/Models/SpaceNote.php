<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Side Space's shared note — one markdown document per surface (a side chat or a channel),
 * edited collaboratively with last-write-wins. There is no history and no per-keystroke
 * merge: a save replaces the whole body and broadcasts it. See {@see \App\Http\Controllers\
 * SpaceNoteController} / {@see \App\Http\Controllers\ChannelSpaceNoteController}.
 */
class SpaceNote extends Model
{
    protected $fillable = ['side_chat_id', 'channel_id', 'updated_by', 'content'];

    public function sideChat(): BelongsTo
    {
        return $this->belongsTo(SideChat::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** Who saved the note last — shown as the "edited by" line. */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The private broadcast stream this note lives on — the surface's own stream, the same
     * one its messages and whiteboard already travel over. See {@see WhiteboardStroke::streamName()}.
     */
    public function streamName(): string
    {
        return $this->side_chat_id
            ? 'sidechat.'.$this->side_chat_id
            : 'channel.'.$this->channel_id;
    }
}
