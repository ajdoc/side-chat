<?php

namespace App\Http\Requests\Calendar;

use App\Http\Requests\MemberRequest;

/**
 * Create, change or delete an entry on a channel's Calendar. A channel has no roster, so
 * membership is the whole gate — the same stance the channel's canvas and notes take. One
 * class serves store, update and destroy; the rules are empty on DELETE.
 */
class ChannelCalendarEventRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->isMethod('delete')) {
            return [];
        }

        return CalendarEventRules::forMethod($this->isMethod('post'));
    }
}
