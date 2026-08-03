<?php

namespace App\Services\Automation\Actions;

use App\Actions\Message\SendMessageAction;
use App\DTOs\Message\SendMessageData;
use App\Models\Channel;
use App\Models\CustomCommand;
use App\Services\Automation\AutomationActionHandler;
use App\Services\Commands\CustomCommandService;
use App\Support\Automation\ActionResult;
use App\Support\Automation\AutomationContext;

/**
 * Post the answer one of this server's custom commands would give.
 *
 * The point of this action is that the built-in features compose rather than sit in silos: a
 * server that has already written `/rules` shouldn't have to write those rules a second time
 * inside a welcome automation. One canned answer, one place to edit it, reachable from a
 * rule as well as from somebody typing.
 *
 * Deliberately *not* a general "run any command". A rule that could invoke `/roll` or a
 * bot's `/deploy` would be a way to make the app do arbitrary things on a trigger, and the
 * loop guard is a depth counter rather than a proof. A canned response has no such reach.
 */
final class RunCommandAction implements AutomationActionHandler
{
    public function __construct(
        private readonly CustomCommandService $commands,
        private readonly SendMessageAction $send,
    ) {}

    public function name(): string
    {
        return 'run_command';
    }

    public function label(): string
    {
        return 'Post a custom command’s response';
    }

    public function schema(): array
    {
        return [
            [
                'key' => 'command_id',
                'type' => 'command',
                'label' => 'Command',
                'required' => true,
            ],
            [
                'key' => 'channel_id',
                'type' => 'channel',
                'label' => 'Channel',
                'required' => false,
                'help' => 'Leave blank to post where the trigger happened.',
            ],
        ];
    }

    public function handle(array $config, AutomationContext $context): ActionResult
    {
        $server = $context->server();
        $bot = $server?->automationBot();

        if ($server === null || $bot?->user === null) {
            return ActionResult::skipped('This server has no bot set to run automations.');
        }

        $command = CustomCommand::where('server_id', $server->getKey())->find($config['command_id'] ?? null);

        if ($command === null) {
            return ActionResult::failed('That command has been deleted.');
        }

        if (! $command->enabled) {
            return ActionResult::skipped("`{$command->name}` is switched off.");
        }

        $channelId = $config['channel_id'] ?? $context->get('channel_id');
        $channel = $channelId === null
            ? null
            : Channel::where('server_id', $server->getKey())->find($channelId);

        if ($channel === null) {
            return ActionResult::skipped('The channel this rule posts to no longer exists.');
        }

        if (! $channel->hasMember($bot->user)) {
            return ActionResult::skipped("The bot isn't in #{$channel->name}.");
        }

        /*
         * The response is rendered against the *rule's* subject, not the command's usual
         * "whoever typed it" — so `{user}` in `/welcome` means the person who just joined.
         *
         * The badge gate and the cooldown are deliberately skipped. Both exist to govern
         * people typing in a channel; a rule the server's own staff wrote has already been
         * authorised, and putting a welcome message on a 30-second cooldown would silently
         * drop the second of two people joining together.
         */
        $subject = $context->subject() ?? $bot->user;
        $body = trim($this->commands->render($command, $channel, $subject));

        if ($body === '') {
            return ActionResult::skipped('The response came out empty.');
        }

        $command->recordUse();
        $message = $this->send->handle($channel, $bot->user, SendMessageData::fromArray(['body' => $body]));

        return ActionResult::ok(null, ['command' => $command->name, 'message_id' => $message->getKey()]);
    }
}
