<?php

namespace App\Http\Requests\Voice;

use App\Models\Server;

/**
 * Starting or stopping a recording of the call.
 *
 * ## Who may
 *
 * In a server's room, **staff** — the same line `MuteVoiceParticipantRequest` draws, and for a
 * related reason: this is not a thing done to a call and undone by rejoining. A recording leaves
 * the room and outlives it, so the power to make one belongs with the people who answer for the
 * place. In a **group chat** it's the owner; in a **DM**, either person, because a room of two
 * has no staff and both are equally its subject.
 *
 * That is a deliberately narrow door. The alternative — anyone in the call may record — makes
 * "who has a copy of this conversation" unanswerable in a channel of two hundred people.
 *
 * ## What it does not do
 *
 * It cannot make a recording *secret*. The flag it sets is broadcast to the whole room and
 * announced in the timeline; there is no code path here that records without saying so, and that
 * is the property worth protecting rather than any particular UI.
 */
class RecordCallRequest extends VoiceChannelRequest
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

        if ($container instanceof Server) {
            return $container->isStaff($user);
        }

        // A group chat's owner, or — in a DM, where `owner_id` is null — either of the two
        // people in it. Membership was already established by the parent.
        return $container?->owner_id === null || $container?->owner_id === $user->getKey();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['recording' => ['required', 'boolean']];
    }
}
