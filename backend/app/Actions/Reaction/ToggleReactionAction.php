<?php

namespace App\Actions\Reaction;

use App\DTOs\Reaction\ToggleReactionData;
use App\Events\ReactionToggled;
use App\Models\Message;
use App\Models\User;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\TriggerRegistry;
use App\Support\Automation\AutomationContext;

final class ToggleReactionAction
{
    public function __construct(private readonly AutomationEngine $automations) {}

    /**
     * Add the reaction, or remove it if this user already reacted with that emoji.
     *
     * Broadcast (unlike a new message) goes to *everyone*, the reactor included: the
     * payload is the full summary, so re-applying it is a no-op for whoever already
     * has it — and that costs less than threading a socket id through to skip them.
     */
    public function handle(Message $message, User $user, ToggleReactionData $data): Message
    {
        $existing = $message->reactions()
            ->where('user_id', $user->id)
            ->where('emoji', $data->emoji)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            $message->reactions()->create([
                'user_id' => $user->id,
                'emoji' => $data->emoji,
            ]);
        }

        $message->load('reactions.user');

        broadcast(new ReactionToggled($message));

        // Both halves are triggers. Removal earns its place because of reaction roles: a
        // badge you take by reacting has to be one you can give up by un-reacting, and
        // there is no other moment at which that is knowable.
        $this->fireAutomations(
            $message,
            $user,
            $data->emoji,
            $existing ? TriggerRegistry::REACTION_REMOVED : TriggerRegistry::REACTION_ADDED,
        );

        return $message;
    }

    /**
     * Tell the rules, if this reaction happened somewhere a server has any.
     *
     * A reaction in a DM or a group chat has no server, and therefore no rules — that isn't
     * a special case to handle, it's simply nothing to do.
     */
    private function fireAutomations(Message $message, User $user, string $emoji, string $trigger): void
    {
        $message->loadMissing('channel');
        $channel = $message->channel;

        if ($channel === null || $channel->server_id === null) {
            return;
        }

        // A bot reacting is not somebody reacting — the same rule the message trigger
        // applies, and the same reason: a rule whose action is "react ✅" must not be able
        // to trigger itself.
        if ($user->is_bot) {
            return;
        }

        $this->automations->fire(new AutomationContext(
            $channel->server_id,
            $trigger,
            [
                'user_id' => $user->getKey(),
                'user_name' => $user->name,
                'channel_id' => $channel->getKey(),
                'channel_name' => $channel->name,
                'message_id' => $message->getKey(),
                'message_author_id' => $message->user_id,
                'emoji' => $emoji,
            ],
        ));
    }
}
