<?php

namespace App\Events;

use App\Models\Server;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody's role in a server changed.
 *
 * Broadcast to the whole server rather than just the person promoted: the member list
 * shows everyone's badge, and the newly-minted admin needs their own settings to unlock
 * without a reload. The payload is the member and their new role — no resource, because
 * (like {@link ServerUpdated}) a broadcast has no single asker to answer `is_staff` for.
 */
class ServerRoleUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Server $server,
        public int $userId,
        public string $role,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('server.'.$this->server->id)];
    }

    public function broadcastAs(): string
    {
        return 'ServerRoleUpdated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['server_id' => $this->server->id, 'user_id' => $this->userId, 'role' => $this->role];
    }
}
