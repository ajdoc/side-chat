<?php

namespace App\Actions\Invite;

use App\Events\JoinRequestResolved;
use App\Models\Server;

final class DeclineJoinRequestsAction
{
    /**
     * Declining simply deletes the request (for now - no record is kept).
     *
     * @param  array<int, int>  $requestIds
     * @return int  number of requests removed
     */
    public function handle(Server $server, array $requestIds): int
    {
        $ids = $server->joinRequests()->whereIn('id', $requestIds)->pluck('id')->all();

        if (empty($ids)) {
            return 0;
        }

        $server->joinRequests()->whereIn('id', $ids)->delete();

        JoinRequestResolved::dispatch($server, $ids, 'declined');

        return count($ids);
    }
}
