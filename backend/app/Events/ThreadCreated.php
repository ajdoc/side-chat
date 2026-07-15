<?php

namespace App\Events;

use App\Http\Resources\ThreadResource;
use App\Models\Thread;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ThreadCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Thread $thread)
    {
        $this->thread->loadMissing('creator', 'parentMessage.user')->loadCount('messages');
    }

    /**
     * Announced on the parent channel so members see the new thread appear.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('channel.'.$this->thread->channel_id)];
    }

    public function broadcastAs(): string
    {
        return 'ThreadCreated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return (new ThreadResource($this->thread))->resolve();
    }
}
