<?php

namespace App\Events;

use App\Models\Thread;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// Thread metadata changed (e.g. its title). Sent to the channel (parent indicator +
// list) and the thread stream (open panel header).
class ThreadUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Thread $thread)
    {
        $this->thread->loadCount('messages');
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('channel.'.$this->thread->channel_id),
            new PrivateChannel('thread.'.$this->thread->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ThreadUpdated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'thread_id' => $this->thread->id,
            'channel_id' => $this->thread->channel_id,
            'message_id' => $this->thread->message_id,
            'name' => $this->thread->name,
            'replies_count' => $this->thread->messages_count ?? 0,
        ];
    }
}
