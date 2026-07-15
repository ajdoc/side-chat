<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ThreadDeleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $threadId,
        public int $channelId,
        public ?int $messageId,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('channel.'.$this->channelId),
            new PrivateChannel('thread.'.$this->threadId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ThreadDeleted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['thread_id' => $this->threadId, 'channel_id' => $this->channelId, 'message_id' => $this->messageId];
    }
}
