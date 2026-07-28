<?php

namespace App\Http\Requests\Voice;

use App\Models\Conversation;
use App\Models\Server;

/**
 * Reaching into somebody else's microphone — the owner's, and nobody else's.
 *
 * Everything else inside a call is open to the whole room, including turning people out
 * of it, because those are things done *to a call* and undone by rejoining. This one is
 * different in kind: it moves a switch on another person's machine, and unmuting in
 * particular opens a live line they had deliberately closed. That only belongs to whoever
 * owns the place the call is in.
 *
 * Unlike ServerOwnerRequest this also answers for a group chat, which has an owner of its
 * own — the person who made the group is as much in charge of its call as a server owner
 * is of theirs. A DM has no owner (`owner_id` is null and neither person owns the other),
 * so a DM's call has nobody who can do this, which is the right answer for a room of two.
 */
class MuteVoiceParticipantRequest extends VoiceChannelRequest
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        $user = $this->user();
        $container = $this->resolveContainer();

        if ($user === null) {
            return false;
        }

        // A server delegates this to its admins along with everything else they run; a
        // group chat has no roles, so it stays with the person who made it.
        if ($container instanceof Server) {
            return $container->isStaff($user);
        }

        return $container instanceof Conversation
            && $container->owner_id !== null
            && $container->owner_id === $user->id;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'muted' => ['required', 'boolean'],
        ];
    }
}
