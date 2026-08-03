<?php

namespace App\Services\Automation\Actions;

use App\Models\Server;
use App\Services\Automation\AutomationActionHandler;
use App\Support\Automation\ActionResult;
use App\Support\Automation\AutomationContext;

/**
 * Change what a member is allowed to do.
 *
 * The one action that grants *authority* rather than decoration, which makes it the one to
 * be careful with. Two guards, and both are here rather than only in the UI because a rule
 * outlives the screen that made it:
 *
 *  - **The owner is untouchable.** Ownership is a column on the server, not a pivot value
 *    (see Server::ROLE_*), and a rule that could demote the owner would be a way to take a
 *    server from the person it belongs to.
 *  - **Only the two real roles.** 'owner' is not assignable here for the same reason.
 *
 * The third guard isn't in this class: a rule *containing* this action may only be created
 * or edited by the server's owner, enforced where automations are saved. Role changes are
 * owner-only everywhere else in the app (ServerOwnerRequest), and an admin who could write
 * "when anyone reacts 👑, make them an admin" would have found a way around that.
 */
final class SetRoleAction implements AutomationActionHandler
{
    public function name(): string
    {
        return 'set_role';
    }

    public function label(): string
    {
        return 'Change a member’s role';
    }

    public function schema(): array
    {
        return [[
            'key' => 'role',
            'type' => 'role',
            'label' => 'Role',
            'required' => true,
            'options' => Server::ROLES,
        ]];
    }

    public function handle(array $config, AutomationContext $context): ActionResult
    {
        $user = $context->subject();
        $server = $context->server();

        if ($user === null || $server === null) {
            return ActionResult::skipped('There is nobody to change.');
        }

        $role = (string) ($config['role'] ?? '');

        if (! in_array($role, Server::ROLES, true)) {
            return ActionResult::failed("`{$role}` isn't a role that can be assigned.");
        }

        if ($server->isOwner($user)) {
            return ActionResult::skipped('The owner’s role can’t be changed.');
        }

        if (! $server->hasMember($user)) {
            return ActionResult::skipped("{$user->name} isn't in this server any more.");
        }

        $previous = $server->roleFor($user);

        if ($previous === $role) {
            return ActionResult::skipped("{$user->name} is already a {$role}.");
        }

        $server->members()->updateExistingPivot($user->getKey(), ['role' => $role]);

        return ActionResult::ok(null, ['role' => $role, 'previous_role' => $previous]);
    }
}
