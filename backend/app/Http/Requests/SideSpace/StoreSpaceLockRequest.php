<?php

namespace App\Http\Requests\SideSpace;

use App\Http\Requests\MemberRequest;
use App\Support\SideSpace\Doors;

/**
 * Locking a door, or changing who may come through it.
 *
 * Membership only at this layer, which is not the whole gate: whether *this* member may lock
 * *this* door depends on the room the door turns out to guard, and that needs the map in hand.
 * It's checked in the controller against {@see Doors::mayAdminister} —
 * the same function the listing and the removal use, so the three can't drift apart.
 *
 * `allowed` is the explicit key-holders only. The people who can always pass — whoever set it,
 * the room's owner, the server's owner — are resolved on the way out and are not stored, so
 * there is nothing to send for them and nothing a client could do by omitting them.
 *
 * `password` is the one field here whose *absence* means something — see the rule.
 */
class StoreSpaceLockRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'allowed' => ['present', 'array', 'max:100'],
            'allowed.*' => ['integer', 'exists:users,id'],
            /*
             * The door's password, if it's to have one.
             *
             * Three different things, and the difference is which key is present rather than
             * which value it holds — so the controller asks `has('password')`, not `validated()`:
             *
             *   - **absent** — leave whatever password the door already had. This is what every
             *     edit of the key-holder list sends, and it must not quietly clear one.
             *   - **null** — take the password off. The door goes back to being a list of people.
             *   - **a string** — set it, which also forgets everybody who had entered the old one.
             *
             * `min:4` because a door with a one-character password is a door that is open, and
             * the person setting it is entitled to be told that rather than discover it.
             */
            'password' => ['sometimes', 'nullable', 'string', 'min:4', 'max:200'],
        ];
    }
}
