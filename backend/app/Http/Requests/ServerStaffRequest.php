<?php

namespace App\Http\Requests;

/**
 * Base request for anything only the people who *run* a server may do: the owner, and the
 * members the owner has made admins.
 *
 * This is where almost everything that used to say "owner only" now lives. The point of an
 * admin is that the owner doesn't have to be awake for the server to work — approving who
 * gets in, adding and renaming channels, handing out Side Space rooms, setting the call's
 * entrance effects. All of those are recoverable, and all of them are the job an owner
 * delegates when they delegate anything at all.
 *
 * What stays with the owner is in {@link ServerOwnerRequest}: deleting the server, and
 * deciding who the admins are. An admin who could appoint admins, or delete the place, is
 * an owner with extra steps.
 *
 * Reuses MemberRequest's route resolution, so the owning server is found the same way
 * whether the route binds a server, a channel, a thread or a message. It resolves to null
 * for a DM or group chat, which refuses those endpoints outright — a chat has no staff.
 */
abstract class ServerStaffRequest extends MemberRequest
{
    public function authorize(): bool
    {
        $server = $this->resolveServer();
        $user = $this->user();

        return $server !== null && $user !== null && $server->isStaff($user);
    }
}
