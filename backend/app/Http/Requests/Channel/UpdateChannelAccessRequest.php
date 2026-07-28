<?php

namespace App\Http\Requests\Channel;

use App\Http\Requests\ServerStaffRequest;

/**
 * Who may be in this channel. Staff only — this is a setting about the shape of the server,
 * which is exactly the kind of thing an admin exists to look after.
 *
 * `member_ids` is sent whole rather than as add/remove operations: the dialog shows the
 * roster as a set of checkboxes, so what it has is a set, and a diff computed on the client
 * would be a diff against whatever it last happened to fetch. The action syncs.
 */
class UpdateChannelAccessRequest extends ServerStaffRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'is_private' => ['required', 'boolean'],
            // Absent (or empty) with is_private true means "staff only" — legitimate, and
            // the reason this isn't `required_if`.
            'member_ids' => ['sometimes', 'array', 'max:500'],
            'member_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
