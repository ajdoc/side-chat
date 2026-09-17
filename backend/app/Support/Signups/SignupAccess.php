<?php

namespace App\Support\Signups;

use App\Models\Channel;
use App\Models\Server;
use App\Models\User;

/**
 * Who runs a channel's sign-ups: a server's staff, or everyone in a DM/group chat, where
 * nobody is above anybody. Filling a slot needs only membership and never comes through here.
 */
final class SignupAccess
{
    public static function canManage(Channel $channel, User $user): bool
    {
        $container = $channel->container();

        return ! $container instanceof Server || $container->isStaff($user);
    }

    public static function authorize(Channel $channel, User $user): void
    {
        abort_unless(self::canManage($channel, $user), 403, 'Only staff can change this.');
    }
}
