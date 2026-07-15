<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerJoinRequest;
use Illuminate\Database\Eloquent\Collection;

final class JoinRequestService
{
    /** @return Collection<int, ServerJoinRequest> */
    public function pendingFor(Server $server): Collection
    {
        return $server->joinRequests()
            ->with('user')
            ->orderBy('id')
            ->get();
    }
}
