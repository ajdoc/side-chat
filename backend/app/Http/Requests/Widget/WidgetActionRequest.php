<?php

namespace App\Http\Requests\Widget;

use App\Http\Requests\MemberRequest;

/**
 * A card action — a play/pause tap, a card dragged between columns. Authorized as
 * channel membership (via the bound {@see \App\Models\Widget}); the handler decides
 * whether the specific action means anything, so `payload` is left free-form.
 */
class WidgetActionRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'max:40'],
            'payload' => ['nullable', 'array'],
        ];
    }
}
