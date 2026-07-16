<?php

namespace App\Http\Requests\Voice;

use App\Models\Conversation;
use App\Models\Server;

/**
 * Disconnecting *other* people from a call is a moderator power, so this narrows the
 * membership check it inherits down to ownership: the server's owner in a server voice
 * channel, the group's owner in a group chat.
 *
 * A DM has no owner — neither person owns the other — so there is nobody who may hang up
 * on the other end. There you leave the call yourself instead, which is why this refuses a
 * DM's channel outright.
 */
class DisconnectVoiceParticipantsRequest extends VoiceChannelRequest
{
    public function authorize(): bool
    {
        // Membership + "this channel can hold a call at all" come from the parent chain.
        if (! parent::authorize()) {
            return false;
        }

        $container = $this->resolveContainer();
        $user = $this->user();

        if ($container instanceof Server) {
            return $container->owner_id === $user->id;
        }

        if ($container instanceof Conversation) {
            return $container->owner_id !== null && $container->owner_id === $user->id;
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Omitted means "everyone but me". Present means that one person.
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
