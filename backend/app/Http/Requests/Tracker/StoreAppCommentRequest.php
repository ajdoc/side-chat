<?php

namespace App\Http\Requests\Tracker;

use App\Http\Requests\MemberRequest;

/**
 * Writing or editing a comment on a work item.
 *
 * The 2000-character ceiling matches the composer the client draws (see the counter under the
 * comment box); a comment longer than that is a message, and messages have a timeline.
 */
class StoreAppCommentRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
        ];
    }
}
