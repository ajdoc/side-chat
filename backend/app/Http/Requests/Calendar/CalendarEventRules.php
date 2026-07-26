<?php

namespace App\Http\Requests\Calendar;

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
        ];
    }
}
