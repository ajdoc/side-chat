<?php

namespace App\Services\Automation\Actions;

use App\Actions\Reaction\ToggleReactionAction;
use App\Events\ReactionToggled;
use App\Models\Message;
use App\Services\Automation\AutomationActionHandler;
use App\Support\Automation\ActionResult;
use App\Support\Automation\AutomationContext;

/**
 * React to the message that triggered the rule.
 *
 * Small, and it earns its place twice over: it's how a bot acknowledges a command without
 * adding a line to the channel, and it's how a reaction-role message gets its emoji put
 * there ready to be clicked.
 *
 * Adds rather than toggles. {@see ToggleReactionAction} is right for a
 * person clicking — the click means "on if off, off if on" — but a rule saying "react ✅"
 * means ✅, and a second run that silently removed it would be a strange reading.
 */
final class AddReactionAction implements AutomationActionHandler
{
    public function name(): string
    {
        return 'add_reaction';
    }

    public function label(): string
    {
        return 'React to the message';
    }

    public function schema(): array
    {
        return [[
            'key' => 'emoji',
            'type' => 'text',
            'label' => 'Emoji',
            'required' => true,
        ]];
    }

    public function handle(array $config, AutomationContext $context): ActionResult
    {
        $server = $context->server();
        $bot = $server?->automationBot();

        if ($bot?->user === null) {
            return ActionResult::skipped('This server has no bot set to run automations.');
        }

        // The message this rule is about — a rule with no message in its context (a member
        // joining) has nothing to react to.
        $message = Message::find($context->get('message_id'));

        if ($message === null) {
            return ActionResult::skipped('There is no message to react to.');
        }

        $emoji = trim((string) ($config['emoji'] ?? ''));

        if ($emoji === '') {
            return ActionResult::failed('No emoji was configured.');
        }

        $existing = $message->reactions()
            ->where('user_id', $bot->user->getKey())
            ->where('emoji', $emoji)
            ->exists();

        if ($existing) {
            return ActionResult::skipped('Already reacted with that.');
        }

        $message->reactions()->create(['user_id' => $bot->user->getKey(), 'emoji' => $emoji]);
        $message->load('reactions.user');

        broadcast(new ReactionToggled($message));

        return ActionResult::ok(null, ['message_id' => $message->getKey(), 'emoji' => $emoji]);
    }
}
