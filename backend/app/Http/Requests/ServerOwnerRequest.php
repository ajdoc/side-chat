<?php

namespace App\Http\Requests;

/**
 * Base request for the two things that stay with the server's *owner* alone: deleting the
 * server, and deciding who its admins are.
 *
 * Everything else that once lived here — renaming, channels, room assignments, call
 * effects, approving joins — moved to {@link ServerStaffRequest}, which the owner also
 * passes. What's left is the pair an admin must not have: one is irreversible and takes
 * the whole place with it, and the other is the ability to appoint themselves.
 *
 * Reuses MemberRequest's route resolution — the owning server is found the same way
 * whether the route binds a server, a channel, a thread or a message.
 */
abstract class ServerOwnerRequest extends MemberRequest
{
    public function authorize(): bool
    {
        $server = $this->resolveServer();
        $user = $this->user();

        return $server !== null && $user !== null && $server->isOwner($user);
    }
}
