<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A message landed somewhere you might not be looking.
 *
 * Never on the channel's own stream: you are subscribed to `channel.{id}` only for the
 * channel you have open, which is precisely the one row in the sidebar that doesn't need
 * a badge. It has to travel on something you're listening to *whatever* you're looking at
 * — which is a different address for a server than for a chat, and is exactly what
 * MessageContainer::notificationChannels() exists to answer.
 *
 * Deliberately carries no message body. It exists to bump a counter.
 */
class ChannelActivity implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message)
    {
        $this->message->loadMissing('channel.server', 'channel.conversation');
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return $this->message->channel->container()?->notificationChannels() ?? [];
    }

    public function broadcastAs(): string
    {
        return 'ChannelActivity';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'channel_id' => $this->message->channel_id,
            // Lets a chat row badge itself without having to know its channel's id.
            'conversation_id' => $this->message->channel->conversation_id,
            'message_id' => $this->message->id,
            'user_id' => $this->message->user_id, // so a client can ignore its own messages
        ];
    }
}
