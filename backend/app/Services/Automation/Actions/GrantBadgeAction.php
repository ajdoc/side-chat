<?php

namespace App\Services\Automation\Actions;

use App\Models\Badge;
use App\Services\Automation\AutomationActionHandler;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\TriggerRegistry;
use App\Support\Automation\ActionResult;
use App\Support\Automation\AutomationContext;
use App\Support\Automation\Subject;

/**
 * Give the member this event is about a badge.
 *
 * Granting fires `badge.granted` in turn, which is what makes "react with 🎮 to join the
 * crew" and "announce new crew members" two separate rules that compose rather than one
 * rule that has to do both. The context carries a depth so that composition can't run away
 * — see {@see AutomationEngine::MAX_DEPTH}.
 */
final class GrantBadgeAction implements AutomationActionHandler
{
    public function __construct(private readonly AutomationEngine $engine) {}

    public function name(): string
    {
        return 'grant_badge';
    }

    public function label(): string
    {
        return 'Give a badge';
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
            return ActionResult::skipped('There is nobody to give it to.');
        }

        // Scoped to the server for the same reason a channel is: an id from elsewhere must
        // not be usable here.
        $badge = Badge::where('server_id', $context->serverId)->find($config['badge_id'] ?? null);

        if ($badge === null) {
            return ActionResult::failed('That badge has been deleted.');
        }

        $server = $context->server();

        // Badges belong to a server, so holding one only means something while you're in it.
        if ($server === null || ! $server->hasMember($user)) {
            return ActionResult::skipped("{$user->name} isn't in this server any more.");
        }

        if (! $badge->grantTo($user)) {
            return ActionResult::skipped("{$user->name} already has {$badge->name}.");
        }

        // Only on a genuine grant — otherwise every re-run of a rule would re-announce a
        // badge somebody has had for a month.
        $this->engine->fire(new AutomationContext(
            $context->serverId,
            TriggerRegistry::BADGE_GRANTED,
            [
                ...Subject::fields($user, $server),
                'badge_id' => $badge->getKey(),
                'badge_name' => $badge->name,
            ],
            $context->depth + 1,
        ));

        return ActionResult::ok(null, ['badge_id' => $badge->getKey(), 'badge_name' => $badge->name]);
    }
}
