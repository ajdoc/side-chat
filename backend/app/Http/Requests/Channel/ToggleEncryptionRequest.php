<?php

namespace App\Http\Requests\Channel;

use App\Http\Requests\MemberRequest;
use App\Models\Conversation;
use App\Models\Server;

/**
 * Turning encryption on or off in a channel.
 *
 * The one endpoint in the app whose authorisation rule differs by container, so it can't
 * reuse ServerStaffRequest (refuses conversations outright) or the plain member rule (lets
 * anyone in a group chat lock it). Both halves are the same principle applied to two
 * different shapes of place: whoever is responsible for it decides.
 *
 *  - **Server channel** — the staff, same as renaming it or making it private.
 *  - **Group chat** — the owner, same rule as renaming (see UpdateConversationRequest): a
 *    setting anybody can flip is a setting nobody can rely on.
 *  - **DM** — either person. There is no owner of a DM and inventing one would mean the
 *    person who happened to send first has a power the other doesn't. Both are equally
 *    exposed by the setting, so both may change it.
 *
 * A channel that has discussions is refused. Its own timeline is not where anybody is
 * talking — the discussions each have theirs — so locking it would encrypt nothing while
 * putting a padlock on the row above every conversation it contains. Encrypt the
 * discussions; they are channels, and this is their endpoint too.
 */
class ToggleEncryptionRequest extends MemberRequest
{
    public function authorize(): bool
    {
        // Membership (and, for a private channel, the access list) first — everything below
        // narrows that, and none of it should be reachable by a non-member.
        if (! parent::authorize()) {
            return false;
        }

        $channel = $this->resolveChannel();
        $user = $this->user();

        if ($channel === null || $user === null || $channel->discussions()->exists()) {
            return false;
        }

        $container = $channel->container();

        return match (true) {
            $container instanceof Server => $container->isStaff($user),
            $container instanceof Conversation => $container->isDm() || $container->owner_id === $user->id,
            default => false,
        };
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'encrypted' => ['required', 'boolean'],
        ];
    }
}
