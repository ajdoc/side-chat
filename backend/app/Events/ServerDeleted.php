<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The server itself is gone — every member should drop it from their rail, and anyone
 * sitting inside it should be shown the door rather than left staring at a dead channel.
 *
 * Broadcast *after* the row is deleted. That's safe: the subscribers on this stream were
 * authorised when they joined, and Reverb doesn't re-check on publish. It has to be after,
 * because until the delete commits there is nothing to announce.
 */
class ServerDeleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $serverId, public string $name) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('server.'.$this->serverId)];
    }

    public function broadcastAs(): string
    {
        return 'ServerDeleted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['server_id' => $this->serverId, 'name' => $this->name];
    }
}
