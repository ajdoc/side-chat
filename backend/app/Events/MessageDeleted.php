<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageDeleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $id,
        public int $channelId,
        public ?int $threadId,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $name = $this->threadId ? 'thread.'.$this->threadId : 'channel.'.$this->channelId;

        return [new PrivateChannel($name)];
    }

    public function broadcastAs(): string
    {
        return 'MessageDeleted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['id' => $this->id, 'channel_id' => $this->channelId, 'thread_id' => $this->threadId];
    }
}
