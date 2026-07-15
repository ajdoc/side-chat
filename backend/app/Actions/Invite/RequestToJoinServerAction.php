<?php

namespace App\Actions\Invite;

use App\Events\JoinRequestCreated;
use App\Models\Server;
use App\Models\ServerJoinRequest;
use App\Models\User;

final class RequestToJoinServerAction
{
    /**
     * Records a pending request to join. Returns null when the user is already a
     * member (nothing to request). Re-opening the same invite is idempotent.
     */
    public function handle(Server $server, User $user): ?ServerJoinRequest
    {
        if ($server->hasMember($user)) {
            return null;
        }

        $request = ServerJoinRequest::firstOrCreate([
            'server_id' => $server->id,
            'user_id' => $user->id,
        ]);

        if ($request->wasRecentlyCreated) {
            JoinRequestCreated::dispatch($request);
        }

        return $request;
    }
}
