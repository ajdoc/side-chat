<?php

namespace App\Http\Requests\SideSpace;

use App\Http\Requests\MemberRequest;

/**
 * Using a piece of furniture. Membership, like reading the map — pressing E on the room's TV is
 * no more privileged than typing `v!` in the channel, and lands on the very same widget.
 *
 * Only the object's id travels. Which widget that opens is the room's business, and the room is
 * the server's copy of it: a client that sent a *type* could point the potted plant at anything.
 */
class InteractWithSpaceObjectRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'object_id' => ['required', 'string', 'max:40'],
        ];
    }
}
