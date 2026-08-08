<?php

namespace App\Listeners;

use App\Events\MessageSent;
use App\Models\Message;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\TriggerRegistry;
use App\Support\Automation\AutomationContext;
use App\Support\Automation\Subject;

/**
 * Turns a message into the `message.created` trigger.
 *
 * Deliberately the same shape — and the same rules — as {@see NotifyBotsOfMessage}, which
 * does this for external bots. Hung off the event rather than called from SendMessageAction
 * because a message can be created from several places (the composer, the bot API, a widget
 * command) and a trigger that fired for some of them would be worse than one that fired for
 * none.
 *
 * What is excluded, and why:
 *
 *  - **Anything a bot wrote.** This is the load-bearing half of the loop guard. A rule that
 *    posts a message causes `message.created`, and if that reached the rules a server would
 *    only need one badly-written pair to run forever at queue speed. The depth counter in
 *    AutomationContext catches cycles that run through other triggers; this catches the
 *    common one outright. It also means an automation bot can never answer itself.
 *  - **Threads and side chats.** Nothing an action can do reaches into either yet, so a rule
 *    that fired there would be an invitation it can't accept.
 *  - **System notices and widget cards.** Not things anybody said.
 */
class RunMessageAutomations
{
    public function __construct(private readonly AutomationEngine $engine) {}

    public function handle(MessageSent $event): void
    {
        $message = $event->message;

        if (! $this->isTrigger($message)) {
            return;
        }

        $message->loadMissing('channel.server');
        $channel = $message->channel;

        if ($channel === null || $channel->server_id === null) {
            return;
        }

        $this->engine->fire(new AutomationContext(
            $channel->server_id,
            TriggerRegistry::MESSAGE_CREATED,
            [
                ...Subject::fields($message->user, $channel->server),
                'channel_id' => $channel->getKey(),
                'channel_name' => $channel->name,
                'message_id' => $message->getKey(),
                'body' => (string) $message->body,
            ],
        ));
    }

    private function isTrigger(Message $message): bool
    {
        return $message->user !== null
            && ! $message->user->is_bot
            // Encrypted messages don't trigger rules. Every condition a `message.created`
            // rule can carry is about the body — contains, matches, starts with — and a rule
            // evaluated against ciphertext wouldn't merely fail to fire, it would fire
            // wrongly and at random. Same recovery as bots: turning encryption off makes the
            // channel's rules live again for everything sent afterwards.
            && ! $message->isEncrypted()
            && $message->thread_id === null
            && $message->side_chat_id === null
            && ! $message->isSystem()
            && ! $message->isWidget();
    }
}
