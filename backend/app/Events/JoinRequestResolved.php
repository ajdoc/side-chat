<?php

namespace App\Events;

use App\Models\Server;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Lightweight ping so every member's pending-requests list and badge stay in sync. */
class JoinRequestResolved implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, int>  $requestIds
     */
    public function __construct(public Server $server, public array $requestIds, public string $status) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('server.'.$this->server->id)];
    }

    public function broadcastAs(): string
    {
        return 'JoinRequestResolved';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ids' => $this->requestIds,
            'status' => $this->status,
        ];
    }
}
