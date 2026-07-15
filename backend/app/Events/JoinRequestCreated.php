<?php

namespace App\Events;

use App\Http\Resources\ServerJoinRequestResource;
use App\Models\ServerJoinRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JoinRequestCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ServerJoinRequest $joinRequest)
    {
        $this->joinRequest->loadMissing('user');
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('server.'.$this->joinRequest->server_id)];
    }

    public function broadcastAs(): string
    {
        return 'JoinRequestCreated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return (new ServerJoinRequestResource($this->joinRequest))->resolve();
    }
}
