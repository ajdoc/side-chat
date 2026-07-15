<?php

namespace App\Http\Requests\Conversation;

use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Validation\Validator;

/**
 * You may only pull someone into a chat if you share a server with them.
 *
 * Same rule as opening a DM, and for the same reason — otherwise "add to group" is a way
 * to put an unsolicited conversation in front of a stranger, and being added to a group is
 * *louder* than being DM'd, not quieter.
 */
trait ChecksReachableMembers
{
    protected function assertReachable(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $me = $this->user();
        if ($me === null) {
            return;
        }

        $service = app(ConversationService::class);

        foreach (User::whereIn('id', (array) $this->input('user_ids', []))->get() as $other) {
            if ($other->id === $me->id) {
                continue;
            }

            if (! $service->sharesAServerWith($me, $other)) {
                $validator->errors()->add(
                    'user_ids',
                    sprintf('You can only add people you share a server with (%s).', $other->name),
                );

                return;
            }
        }
    }
}
