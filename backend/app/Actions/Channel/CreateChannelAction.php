<?php

namespace App\Actions\Channel;

use App\DTOs\Channel\CreateChannelData;
use App\Models\Channel;
use App\Models\Server;

final class CreateChannelAction
{
    public function handle(Server $server, CreateChannelData $data): Channel
    {
        return $server->channels()->create([
            'name' => $data->name,
            'type' => $data->type,
            'position' => ((int) $server->channels()->max('position')) + 1,
        ]);
    }
}
