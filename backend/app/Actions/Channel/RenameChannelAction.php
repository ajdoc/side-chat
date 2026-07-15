<?php

namespace App\Actions\Channel;

use App\DTOs\Channel\UpdateChannelData;
use App\Events\ChannelUpdated;
use App\Models\Channel;

final class RenameChannelAction
{
    public function handle(Channel $channel, UpdateChannelData $data): Channel
    {
        $channel->update(['name' => $data->name]);

        broadcast(new ChannelUpdated($channel));

        return $channel;
    }
}
