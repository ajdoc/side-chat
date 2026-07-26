<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry on a Side Desk's shared Calendar. Like a {@see CanvasItem}, it points at exactly
 * one surface — a side chat or a channel — and every member of that surface sees the same
 * schedule. See {@see \App\Http\Controllers\CalendarController} /
 * {@see \App\Http\Controllers\ChannelCalendarController}.
 */
class CalendarEvent extends Model
{
    protected $fillable = [
        'side_chat_id', 'channel_id', 'user_id',
        'title', 'description', 'starts_at', 'ends_at', 'all_day', 'color',
    ];

    /**
     * Defaults on the *model*, not only on the column.
     *
     * The column defaults cover what lands in the database, but the instance handed straight
     * back to the caller after a create has never been read from it — so an event created
     * without `all_day` serialised as null, and the client, quite reasonably, drew it as neither
     * timed nor all-day. Setting them here means the response and the row agree from the start.
     */
    protected $attributes = [
        'all_day' => false,
        'color' => 'primary',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
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

    /** Who put it in the calendar. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The private broadcast stream this event lives on — the surface's own stream, the same one
     * its messages, board, notes and canvas already travel over. That shared stream is what
     * makes the Calendar app and the Calendar canvas card converge without any extra plumbing:
     * both are subscribed to it, so a change made in either lands in both.
     *
     * See {@see WhiteboardStroke::streamName()}.
     */
    public function streamName(): string
    {
        return $this->side_chat_id
            ? 'sidechat.'.$this->side_chat_id
            : 'channel.'.$this->channel_id;
    }
}
