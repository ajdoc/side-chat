<?php

namespace App\Http\Requests\Space;

use App\Http\Requests\MemberRequest;

/**
 * Saving a channel note: channel membership (from {@see MemberRequest}) plus the note body.
 */
class UpdateChannelSpaceNoteRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'content' => ['nullable', 'string', 'max:100000'],
        ];
    }
}
