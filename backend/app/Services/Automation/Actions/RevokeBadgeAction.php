<?php

namespace App\Services\Automation\Actions;

use App\Models\Badge;
use App\Services\Automation\AutomationActionHandler;
use App\Support\Automation\ActionResult;
use App\Support\Automation\AutomationContext;

/**
 * Take a badge back.
 *
 * The other half of {@see GrantBadgeAction}, and the half that makes a reaction role work
 * both ways — un-react and you lose it. No trigger fires on revocation: nothing has asked
 * for one, and an event nobody listens to is a promise to keep supplying it.
 */
final class RevokeBadgeAction implements AutomationActionHandler
{
    public function name(): string
    {
        return 'revoke_badge';
    }

    public function label(): string
    {
        return 'Take a badge away';
    }

    public function schema(): array
    {
        return [[
            'key' => 'badge_id',
            'type' => 'badge',
            'label' => 'Badge',
            'required' => true,
        ]];
    }

    public function handle(array $config, AutomationContext $context): ActionResult
    {
        $user = $context->subject();

        if ($user === null) {
            return ActionResult::skipped('There is nobody to take it from.');
        }

        $badge = Badge::where('server_id', $context->serverId)->find($config['badge_id'] ?? null);

        if ($badge === null) {
            return ActionResult::failed('That badge has been deleted.');
        }

        if (! $badge->revokeFrom($user)) {
            return ActionResult::skipped("{$user->name} didn't have {$badge->name}.");
        }

        return ActionResult::ok(null, ['badge_id' => $badge->getKey(), 'badge_name' => $badge->name]);
    }
}
