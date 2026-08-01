<?php

namespace App\Listeners;

use App\Events\MessageSent;
use App\Http\Resources\MessageResource;
use App\Jobs\DeliverBotEvent;
use App\Models\Bot;
use App\Models\Message;
use Illuminate\Support\Str;

/**
 * Turns a message into a webhook delivery for every bot that should hear about it.
 *
 * Hung off MessageSent rather than called from SendMessageAction on purpose: a message can
 * be created from several places (the composer, the bot API, a widget command), and a bot
 * that heard about some of them would be worse than one that heard about none. The event
 * is the one thing every path already fires.
 *
 * Deliberately narrow about what counts as an event:
 *
 *  - **Nothing a bot wrote.** Two bots that each answer the other would otherwise loop
 *    forever at queue speed, and nothing about a token stops a bot answering *itself*.
 *    Bots can still be driven by people, which is the case that matters. If bot-to-bot
 *    ever earns its place it should arrive as an explicit opt-in with a depth limit, not
 *    as the default.
 *  - **Main timeline only.** Threads and side chats fire the same event, but a bot has no
 *    way to reply into either yet, so telling it would be an invitation it can't accept.
 *  - **Real messages only.** System notices and widget cards aren't things anybody said.
 */
class NotifyBotsOfMessage
{
    public function handle(MessageSent $event): void
    {
        $message = $event->message;

        if (! $this->isDeliverable($message)) {
            return;
        }

        $message->loadMissing('channel');
        $channel = $message->channel;

        if ($channel === null || $channel->server_id === null) {
            return;
        }

        $bots = Bot::with('user')
            ->where('server_id', $channel->server_id)
            ->whereNotNull('webhook_url')
            ->whereNull('webhook_disabled_at')
            ->get();

        // Snapshotted once and shared by every delivery: the payload is identical for all
        // of them, and re-resolving it per bot would re-run the resource for no reason.
        $data = (new MessageResource($message))->resolve();

        foreach ($bots as $bot) {
            if (! $bot->subscribesTo('message.created')) {
                continue;
            }

            // A bot hears only what it could read. Same rule as the send side, so a
            // private channel it hasn't been added to is silent rather than merely
            // unreplyable — otherwise a webhook would leak the history that channel exists
            // to keep.
            if ($bot->user === null || ! $channel->hasMember($bot->user)) {
                continue;
            }

            DeliverBotEvent::dispatch($bot->id, 'message.created', $data, (string) Str::uuid());
        }
    }

    private function isDeliverable(Message $message): bool
    {
        return $message->user !== null
            && ! $message->user->is_bot
            && $message->thread_id === null
            && $message->side_chat_id === null
            && ! $message->isSystem()
            && ! $message->isWidget();
    }
}
