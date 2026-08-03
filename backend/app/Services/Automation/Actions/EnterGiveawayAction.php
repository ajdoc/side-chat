<?php

namespace App\Services\Automation\Actions;

use App\Models\Giveaway;
use App\Services\Automation\AutomationActionHandler;
use App\Support\Automation\ActionResult;
use App\Support\Automation\AutomationContext;

/**
 * Put whoever just reacted into a giveaway.
 *
 * This is the whole entry mechanism. Creating a giveaway creates a `reaction.added` rule
 * whose condition is the announcement's message id and whose action is this — so entries
 * arrive through the automation engine like everything else, rather than through a listener
 * bolted onto the reaction path that would have to be kept in step with it.
 *
 * It's in the action registry, so it also shows up in the builder. That's fine and slightly
 * useful — "when somebody gets the Veteran badge, enter them in the draw" is a legitimate
 * thing to want — and hiding it would mean a second, private notion of what an action is.
 */
final class EnterGiveawayAction implements AutomationActionHandler
{
    public function name(): string
    {
        return 'enter_giveaway';
    }

    public function label(): string
    {
        return 'Enter the giveaway';
    }

    public function schema(): array
    {
        return [[
            'key' => 'giveaway_id',
            'type' => 'giveaway',
            'label' => 'Giveaway',
            'required' => true,
        ]];
    }

    public function handle(array $config, AutomationContext $context): ActionResult
    {
        $user = $context->subject();

        if ($user === null) {
            return ActionResult::skipped('There is nobody to enter.');
        }

        $giveaway = Giveaway::with('requiredBadge')
            ->where('server_id', $context->serverId)
            ->find($config['giveaway_id'] ?? null);

        if ($giveaway === null) {
            return ActionResult::failed('That giveaway has been deleted.');
        }

        // Reacting to an old announcement is not an entry. Checked here rather than by
        // deleting the rule at closing time, because the rule and the deadline are two
        // different things and only one of them is the truth.
        if (! $giveaway->isOpen()) {
            return ActionResult::skipped("The {$giveaway->prize} giveaway has closed.");
        }

        $badge = $giveaway->requiredBadge;

        if ($badge !== null && ! $badge->holders()->whereKey($user->getKey())->exists()) {
            return ActionResult::skipped("{$user->name} doesn't have the {$badge->name} badge.");
        }

        $server = $context->server();

        if ($server === null || ! $server->hasMember($user)) {
            return ActionResult::skipped("{$user->name} isn't in this server.");
        }

        // Reacting twice is not two chances — the unique index says so, and this says why.
        if ($giveaway->entries()->where('user_id', $user->getKey())->exists()) {
            return ActionResult::skipped("{$user->name} is already entered.");
        }

        $giveaway->entries()->create(['user_id' => $user->getKey()]);

        return ActionResult::ok(null, ['giveaway_id' => $giveaway->getKey(), 'prize' => $giveaway->prize]);
    }
}
