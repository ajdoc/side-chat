<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A channel is gone.
 *
 * Goes out on the *server* stream, not the channel's own: everyone with the sidebar open
 * has to drop the row, and only the handful of people actually looking at that channel
 * are subscribed to `channel.{id}` — which is, in any case, a stream that no longer has
 * anything behind it.
 */
class ChannelDeleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $channelId, public int $serverId) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('server.'.$this->serverId)];
    }

    public function broadcastAs(): string
    {
        return 'ChannelDeleted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['channel_id' => $this->channelId, 'server_id' => $this->serverId];
    }
}
