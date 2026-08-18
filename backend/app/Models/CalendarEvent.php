<?php

namespace App\Models;

use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ChannelCalendarController;
use App\Models\Concerns\HasAppActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry on a Side Desk's shared Calendar. Like a {@see CanvasItem}, it points at exactly
 * one surface — a side chat or a channel — and every member of that surface sees the same
 * schedule. See {@see CalendarController} /
 * {@see ChannelCalendarController}.
 */
class CalendarEvent extends Model
{
    /** Comments, tags and a history — see the trait. */
    use HasAppActivity;

    protected $fillable = [
        'side_chat_id', 'channel_id', 'user_id',
        'title', 'description', 'starts_at', 'ends_at', 'all_day', 'color',
        'remind_minutes', 'reminded_at', 'room_channel_id',
    ];

    /** How long before the start a reminder may be set for — what the editor offers. */
    public const REMIND_CHOICES = [0, 5, 10, 15, 30, 60, 120, 1440];

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
            'remind_minutes' => 'integer',
            'reminded_at' => 'datetime',
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

    /**
     * The voice room or Side Space this happens in, when it happens in one.
     *
     * Separate from `channel`, which is where the entry is *written down*. A team's calendar
     * usually lives on a text channel and its standup happens in a voice room, so collapsing
     * the two would make "where is this" unanswerable for the common case.
     */
    public function roomChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'room_channel_id');
    }

    /** Is a reminder still owed on this entry? */
    public function awaitsReminder(): bool
    {
        return $this->remind_minutes !== null && $this->reminded_at === null;
    }

    /** When its reminder should go out. */
    public function remindAt(): ?\Illuminate\Support\Carbon
    {
        return $this->remind_minutes === null || $this->starts_at === null
            ? null
            : $this->starts_at->copy()->subMinutes($this->remind_minutes);
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
