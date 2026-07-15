<?php

namespace App\Actions\Server;

use App\DTOs\Server\UpdateServerData;
use App\Events\ServerUpdated;
use App\Models\Server;

final class RenameServerAction
{
    public function handle(Server $server, UpdateServerData $data): Server
    {
        $server->update(['name' => $data->name]);

        broadcast(new ServerUpdated($server));

        return $server;
    }
}
