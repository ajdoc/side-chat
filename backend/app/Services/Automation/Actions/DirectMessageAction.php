<?php

namespace App\Services\Automation\Actions;

use App\Actions\Conversation\CreateDirectMessageAction;
use App\Actions\Message\SendMessageAction;
use App\DTOs\Message\SendMessageData;
use App\Services\Automation\AutomationActionHandler;
use App\Support\Automation\ActionResult;
use App\Support\Automation\AutomationContext;
use App\Support\Automation\Template;

/**
 * Say it to one person instead of to the room.
 *
 * The right shape for the rules that are really instructions — "here's how to get verified",
 * "you've been muted, here's why" — which are noise in a channel and useful in a DM.
 *
 * A conversation owns a Channel, so once the DM exists this is the ordinary send path again;
 * {@see CreateDirectMessageAction} is idempotent, so a rule that fires weekly doesn't leave
 * a trail of empty conversations.
 */
final class DirectMessageAction implements AutomationActionHandler
{
    public function __construct(
        private readonly CreateDirectMessageAction $conversations,
        private readonly SendMessageAction $send,
    ) {}

    public function name(): string
    {
        return 'dm_user';
    }

    public function label(): string
    {
        return 'Send a direct message';
    }

    public function schema(): array
    {
        return [[
            'key' => 'body',
            'type' => 'textarea',
            'label' => 'Message',
            'required' => true,
            'placeholders' => ['user', 'server'],
        ]];
    }

    public function handle(array $config, AutomationContext $context): ActionResult
    {
        $server = $context->server();
        $bot = $server?->automationBot();

        if ($server === null || $bot?->user === null) {
            return ActionResult::skipped('This server has no bot set to run automations.');
        }

        $user = $context->subject();

        if ($user === null) {
            return ActionResult::skipped('There is nobody to write to.');
        }

        // A DM from a server's bot is only defensible while they're both in that server.
        // Once somebody has left, an unsolicited message from a bot they can no longer see
        // the server of is just a stranger writing to them.
        if (! $server->hasMember($user)) {
            return ActionResult::skipped("{$user->name} isn't in this server any more.");
        }

        $body = trim(Template::render((string) ($config['body'] ?? ''), $context->with([
            'server_name' => $server->name,
        ])));

        if ($body === '') {
            return ActionResult::skipped('The message came out empty.');
        }

        $conversation = $this->conversations->handle($bot->user, $user);
        $channel = $conversation->channel;

        if ($channel === null) {
            return ActionResult::failed('That conversation has no channel.');
        }

        $message = $this->send->handle($channel, $bot->user, SendMessageData::fromArray(['body' => $body]));

        return ActionResult::ok(null, ['conversation_id' => $conversation->getKey(), 'message_id' => $message->getKey()]);
    }
}
