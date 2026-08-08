<?php

namespace App\Actions\Server;

use App\DTOs\Server\UpdateServerData;
use App\Events\ServerUpdated;
use App\Models\Server;

final class RenameServerAction
{
    public function handle(Server $server, UpdateServerData $data): Server
    {
        $server->update(array_filter([
            'name' => $data->name,
            // Left alone when the payload doesn't mention it: this endpoint is the rename, and a
            // rename must not quietly reset who may start a discussion.
            'discussion_creation' => $data->discussion_creation,
        ], fn ($value) => $value !== null));

        broadcast(new ServerUpdated($server));

        return $server;
    }
}
