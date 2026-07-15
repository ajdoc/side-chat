<?php

namespace App\Services;

use App\Models\Server;
use App\Models\User;

final class InviteService
{
    public function resolve(string $code): ?Server
    {
        return Server::where('invite_code', $code)->first();
    }

    /** 'member' | 'pending' | 'none' */
    public function statusFor(Server $server, User $user): string
    {
        if ($server->hasMember($user)) {
            return 'member';
        }

        if ($server->joinRequests()->where('user_id', $user->id)->exists()) {
            return 'pending';
        }

        return 'none';
    }
}
