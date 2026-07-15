<?php

namespace App\Http\Requests;

/**
 * Base request for anything only the server's *owner* may do — currently the two
 * irreversible ones: deleting a channel, and deleting the server.
 *
 * Membership is not enough here. Creating a channel is additive and any member may do it;
 * destroying one takes the whole channel's history and files with it, and there is no
 * undo, so it stays with the person who owns the place.
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

        return $server !== null && $user !== null && $server->owner_id === $user->id;
    }
}
