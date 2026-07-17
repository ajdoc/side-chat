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
        public ?int $sideChatId = null,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $name = \App\Models\Message::streamNameFor($this->channelId, $this->threadId, $this->sideChatId);

        return [new PrivateChannel($name)];
    }

    public function broadcastAs(): string
    {
        return 'MessageDeleted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channelId,
            'thread_id' => $this->threadId,
            'side_chat_id' => $this->sideChatId,
        ];
    }
}
