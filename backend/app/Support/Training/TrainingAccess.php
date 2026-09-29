<?php

namespace App\Support\Training;

use App\Models\Channel;
use App\Models\Server;
use App\Models\User;

/**
 * Who runs a channel's training programs: a server's staff, or everyone in a DM/group chat.
 * Ticking off your own blocks needs only membership and never comes through here.
 */
final class TrainingAccess
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
