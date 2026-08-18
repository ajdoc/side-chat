<?php

namespace App\Http\Requests\Calendar;

use App\Models\CalendarEvent;

/**
 * Validation for a Calendar entry, shared by the channel and side chat requests so both gates
 * ({@see ChannelCalendarEventRequest}, {@see CalendarEventRequest}) accept exactly the same
 * body. On update everything is optional — dragging an event to another day saves just the
 * times.
 */
final class CalendarEventRules
{
    /** The named colours a client may pick; the palette they map to is the client's business. */
    public const COLORS = ['primary', 'green', 'amber', 'rose', 'violet', 'teal', 'slate'];

    /** @return array<string, mixed> */
    public static function forMethod(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$req, 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'starts_at' => [$req, 'date'],
            // Allowed to equal `starts_at` (a zero-length marker), so `after_or_equal` rather
            // than `after`. Null is how you say "this is a moment, not a span".
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'all_day' => ['sometimes', 'boolean'],
            'color' => ['sometimes', 'string', 'in:'.implode(',', self::COLORS)],
            /*
             * Post a notice in the channel this many minutes before it starts. Null clears it.
             *
             * A fixed set rather than any integer: the editor is a dropdown, and an arbitrary
             * number invites "remind me 7 minutes before", which nobody wants and every list of
             * reminders then has to render. 0 means "when it starts", which is a real answer.
             */
            'remind_minutes' => ['sometimes', 'nullable', 'integer', 'in:'.implode(',', CalendarEvent::REMIND_CHOICES)],
            // Checked against *this* server's rooms by the request class — an id alone could
            // otherwise name a channel the author can't see.
            'room_channel_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
