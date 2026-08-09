<?php

namespace App\Http\Requests\Channel;

use App\Http\Requests\MemberRequest;
use App\Support\Notifications\NotifyLevel;
use Illuminate\Validation\Rule;

class UpdateNotificationSettingRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Explicitly nullable, and that null is meaningful: it clears the override so
            // the channel goes back to following the account default. Not the same as
            // 'all', which pins it there whatever the default becomes later.
            'notify_level' => ['sometimes', 'nullable', Rule::in(NotifyLevel::values())],
            // Minutes of quiet from now. Null lifts it. Capped at a week — beyond that the
            // honest answer is "set the level to none", which doesn't silently come back.
            'mute_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10080'],
        ];
    }
}
