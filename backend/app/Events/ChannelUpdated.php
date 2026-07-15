<?php

namespace App\Events;

use App\Http\Resources\ChannelResource;
use App\Models\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A channel's metadata changed — currently only its name.
 *
 * On the *server* stream, like ChannelDeleted and for the same reason: the sidebar is
 * where a channel's name is read, and everyone has the sidebar open while only the people
 * inside the channel are on `channel.{id}`.
 *
 * Deliberately carries no unread_count. That number is per-viewer, and this one payload
 * goes to every member — see ChannelResource, which omits it when nobody asked on behalf
 * of a particular user. The client patches the name and leaves its own badge alone.
 */
class ChannelUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Channel $channel) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('server.'.$this->channel->server_id)];
    }

    public function broadcastAs(): string
    {
        return 'ChannelUpdated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return (new ChannelResource($this->channel))->resolve();
    }
}
