<?php

namespace App\Events;

use App\Models\Server;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A server's metadata changed — currently only its name.
 *
 * Just the id and the name, rather than a ServerResource: that resource carries `is_owner`,
 * which is a fact about *the person asking*, and a broadcast has no one asker. Sending it
 * would hand every member the owner's answer. The client patches the name it already has.
 */
class ServerUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Server $server) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('server.'.$this->server->id)];
    }

    public function broadcastAs(): string
    {
        return 'ServerUpdated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['server_id' => $this->server->id, 'name' => $this->server->name];
    }
}
