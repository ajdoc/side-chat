<?php

namespace App\Http\Requests\SideSpace;

use App\Http\Requests\ServerStaffRequest;

/**
 * Making people follow you round the room. The people who run the server, and nobody else.
 *
 * Sits with {@link AssignSpaceRoomOwnerRequest} on the shut side of the Side Space's gates, and
 * for a sharper reason than that one: appointing a room owner changes a row, this moves a
 * person's avatar without asking them. Consent is deliberately not part of it — a summon that
 * could be declined is a summon that doesn't work in the moment it exists for, which is getting
 * a room full of people to the thing being shown. Staff-only *is* the safeguard, so nothing
 * below this line may loosen it.
 *
 * An omitted `user_ids` means everybody in the room. `following: false` releases instead, and
 * takes the same shape so calling one off is the same call as calling it on.
 */
class SummonSpaceRequest extends ServerStaffRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_ids' => ['nullable', 'array', 'max:200'],
            'user_ids.*' => ['integer'],
            'following' => ['required', 'boolean'],
        ];
    }
}
