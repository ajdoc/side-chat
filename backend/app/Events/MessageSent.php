<?php

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message)
    {
        $this->message->loadMissing('user', 'replyTo.user', 'attachments', 'reactions.user', 'linkPreviews');
    }

    /**
     * Thread messages go to the thread stream; everything else to the channel.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $name = $this->message->thread_id
            ? 'thread.'.$this->message->thread_id
            : 'channel.'.$this->message->channel_id;

        return [new PrivateChannel($name)];
    }

    public function broadcastAs(): string
    {
        return 'MessageSent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return (new MessageResource($this->message))->resolve();
    }
}
