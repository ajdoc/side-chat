<?php

namespace App\Http\Requests\SideSpace;

use App\Http\Requests\MemberRequest;

/**
 * Saying the password at a locked door.
 *
 * Membership only, and deliberately nothing more: the whole point of a password is that the
 * person at the door has no other claim on the room. What they know is the gate, and it's
 * checked in the controller against the hash — the one place it can be.
 *
 * Attempts are throttled on the route rather than counted here, because the thing worth limiting
 * is guessing at a door, which is a rate, not a rule about this request.
 */
class EnterSpaceLockRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:200'],
        ];
    }
}
