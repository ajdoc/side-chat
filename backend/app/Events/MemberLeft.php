<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Somebody left the server — drop them from the member-derived bits of the UI. */
class MemberLeft implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $serverId, public int $userId) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('server.'.$this->serverId)];
    }

    public function broadcastAs(): string
    {
        return 'MemberLeft';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['server_id' => $this->serverId, 'user_id' => $this->userId];
    }
}
