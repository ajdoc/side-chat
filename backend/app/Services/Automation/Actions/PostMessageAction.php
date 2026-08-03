<?php

namespace App\Services\Automation\Actions;

use App\Actions\Message\SendMessageAction;
use App\DTOs\Message\SendMessageData;
use App\Models\Channel;
use App\Services\Automation\AutomationActionHandler;
use App\Support\Automation\ActionResult;
use App\Support\Automation\AutomationContext;
use App\Support\Automation\Template;

/**
 * Say something in a channel.
 *
 * The one action nearly every rule ends with, and the one that decides what an automation
 * *is* from a member's point of view: it goes out through {@see SendMessageAction} as the
 * server's automation bot, so it renders with a name and a BOT badge, its mentions notify,
 * its links unfurl, and it arrives over the websocket like anything else. Reimplementing
 * any of that here would mean a bot's messages were subtly second-class.
 */
final class PostMessageAction implements AutomationActionHandler
{
    public function __construct(private readonly SendMessageAction $send) {}

    public function name(): string
    {
        return 'post_message';
    }

    public function label(): string
    {
        return 'Post a message';
    }

    public function schema(): array
    {
        return [
            [
                'key' => 'channel_id',
                'type' => 'channel',
                'label' => 'Channel',
                'required' => false,
                // Blank means "wherever this happened", which is what a rule answering a
                // message almost always wants and what a rule about a member joining
                // cannot have. Both are handled by resolving at run time.
                'help' => 'Leave blank to post where the trigger happened.',
            ],
            [
                /*
                 * The same message, in more rooms — one step, not one step per room.
                 *
                 * Steps run in order because some of them depend on the one before ("grant
                 * the badge, then announce it"). Posting the same words to three channels
                 * has no such ordering: it's one thing that happens in three places, and
                 * making it three steps both misrepresents that and means editing the
                 * wording three times.
                 */
                'key' => 'extra_channel_ids',
                'type' => 'channels',
                'label' => 'Also post in',
                'required' => false,
            ],
            [
                'key' => 'body',
                'type' => 'textarea',
                'label' => 'Message',
                'required' => true,
                'placeholders' => ['user', 'server', 'channel'],
            ],
        ];
    }

    public function handle(array $config, AutomationContext $context): ActionResult
    {
        $server = $context->server();
        $bot = $server?->automationBot();

        // No bot chosen yet. A skip rather than a failure — the rule is fine, the server
        // just hasn't said who should speak. The dashboard turns this line into the prompt
        // to pick one.
        if ($server === null || $bot?->user === null) {
            return ActionResult::skipped('This server has no bot set to run automations.');
        }

        $channels = $this->channels($config, $context, $server->getKey());

        if ($channels->isEmpty()) {
            return ActionResult::skipped('The channel this rule posts to no longer exists.');
        }

        $body = trim(Template::render((string) ($config['body'] ?? ''), $context->with([
            'server_name' => $server->name,
        ])));

        if ($body === '') {
            // Every placeholder came back empty, or the template was blank. Posting an
            // empty message would be a confusing thing for a room to see.
            return ActionResult::skipped('The message came out empty.');
        }

        $posted = [];
        $refused = [];

        foreach ($channels as $channel) {
            /*
             * The bot is a member of the server but not necessarily of a *private* channel —
             * the same rule people are held to (see BOTS.md, "Access settings"). Posting
             * anyway would let a rule put messages into rooms its bot was deliberately kept
             * out of.
             *
             * One room refusing doesn't stop the others: a rule announcing in three channels
             * shouldn't go silent because the bot was removed from one of them.
             */
            if (! $channel->hasMember($bot->user)) {
                $refused[] = $channel->name;

                continue;
            }

            $message = $this->send->handle($channel, $bot->user, SendMessageData::fromArray(['body' => $body]));
            $posted[$channel->getKey()] = $message->getKey();
        }

        $note = $refused === [] ? null : 'Not posted in #'.implode(', #', $refused).' — the bot isn’t in there.';

        // Nothing got through at all: a skip, with the reason, rather than a silent success.
        if ($posted === []) {
            return ActionResult::skipped($note ?? 'There was nowhere to post.');
        }

        return ActionResult::ok($note, ['message_ids' => $posted]);
    }

    /**
     * Every channel this step posts to, in order.
     *
     * The primary falls back to wherever the trigger happened; the extras never do — an
     * extra is a room somebody named, so a rule that names none has none.
     *
     * @param  array<string, mixed>  $config
     * @return \Illuminate\Support\Collection<int, Channel>
     */
    private function channels(array $config, AutomationContext $context, int $serverId)
    {
        $ids = array_values(array_unique(array_filter([
            $config['channel_id'] ?? $context->get('channel_id'),
            ...(array) ($config['extra_channel_ids'] ?? []),
        ])));

        // Scoped to the server: a channel id is not a capability, and a rule in one server
        // must not be able to post into another by holding an id from it.
        return Channel::where('server_id', $serverId)->findMany($ids);
    }
}
