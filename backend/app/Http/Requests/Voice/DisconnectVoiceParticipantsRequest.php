<?php

namespace App\Http\Requests\Voice;

/**
 * Disconnecting *other* people from a call is open to anyone in the channel — the same
 * membership check every other in-call action inherits, and nothing more. A member who can
 * join the call can also turn someone out of it.
 */
class DisconnectVoiceParticipantsRequest extends VoiceChannelRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Omitted means "everyone but me". Present means that one person.
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
