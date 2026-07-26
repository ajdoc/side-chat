<?php

namespace App\Http\Requests\Calendar;

use App\Models\SideChat;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create, change or delete an entry on a side chat's Calendar — a taking-part power, so you
 * have to be on the roster, exactly as with the canvas ({@see \App\Http\Requests\Canvas\
 * CanvasItemRequest}) and the board. Reading only needs channel membership.
 */
class CalendarEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        $sideChat = $this->route('sideChat');
        $user = $this->user();

        return $sideChat instanceof SideChat
            && $user !== null
            && $sideChat->hasParticipant($user);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->isMethod('delete')) {
            return [];
        }

        return CalendarEventRules::forMethod($this->isMethod('post'));
    }
}
