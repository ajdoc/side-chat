<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One card on a Side Desk's Open Canvas — a markdown note or a checklist, freely placed on
 * a surface's 2D board. Like a {@see WhiteboardStroke}, a card points at exactly one surface
 * (a side chat or a channel) and its `content` is a free-form blob whose shape is the card
 * `kind`'s contract with its Vue renderer, not the API's. See {@see \App\Http\Controllers\
 * CanvasController} / {@see \App\Http\Controllers\ChannelCanvasController}.
 */
class CanvasItem extends Model
{
    protected $fillable = ['side_chat_id', 'channel_id', 'user_id', 'widget_id', 'kind', 'content', 'x', 'y', 'w', 'h', 'z'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'x' => 'integer',
            'y' => 'integer',
            'w' => 'integer',
            'h' => 'integer',
            'z' => 'integer',
        ];
    }

    public function sideChat(): BelongsTo
    {
        return $this->belongsTo(SideChat::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** For a `widget` card, the interactive widget it places — null for note/todo cards. */
    public function widget(): BelongsTo
    {
        return $this->belongsTo(Widget::class);
    }

    /** The private broadcast stream this card lives on — see {@see WhiteboardStroke::streamName()}. */
    public function streamName(): string
    {
        return $this->side_chat_id
            ? 'sidechat.'.$this->side_chat_id
            : 'channel.'.$this->channel_id;
    }
}
