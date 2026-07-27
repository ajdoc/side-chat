<?php

namespace App\Http\Requests\SideSpace;

use App\Http\Requests\MemberRequest;

/**
 * Locking a door, or changing who may come through it.
 *
 * Membership only at this layer, which is not the whole gate: whether *this* member may lock
 * *this* door depends on the room the door turns out to guard, and that needs the map in hand.
 * It's checked in the controller against {@see \App\Support\SideSpace\Doors::mayAdminister} —
 * the same function the listing and the removal use, so the three can't drift apart.
 *
 * `allowed` is the explicit key-holders only. The people who can always pass — whoever set it,
 * the room's owner, the server's owner — are resolved on the way out and are not stored, so
 * there is nothing to send for them and nothing a client could do by omitting them.
 */
class StoreSpaceLockRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'allowed' => ['present', 'array', 'max:100'],
            'allowed.*' => ['integer', 'exists:users,id'],
        ];
    }
}
