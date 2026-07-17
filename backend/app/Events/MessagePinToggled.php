<?php

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A message was pinned or unpinned.
 *
 * The only event that goes out on *both* streams, because a pin is the one thing that is
 * simultaneously true in two places. It always reaches `channel.{id}`, even for a message
 * that lives in a thread — the Pinned tab belongs to the channel and lists thread messages
 * too, so a pin made three levels deep still has to reach everyone watching the channel.
 * And when the message is in a thread it also goes to `thread.{id}`, or the people with
 * that thread open would be the only ones who never saw it happen.
 *
 * (Contrast MessageSent, which goes to one or the other: a reply is *read* in exactly one
 * place, so it's delivered to exactly one.)
 *
 * Carries the whole message rather than a bare id, so a client that has never loaded it —
 * the far end of a long backlog, or a thread it never opened — can render the pinned row
 * without going back to the API for it.
 */
class MessagePinToggled implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message)
    {
        $this->message->loadMissing('user', 'replyTo.user', 'attachments', 'reactions.user', 'linkPreviews');
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('channel.'.$this->message->channel_id)];

        // Also to the branch it lives in, so an open thread/side-chat panel sees the pin too.
        if ($this->message->thread_id) {
            $channels[] = new PrivateChannel('thread.'.$this->message->thread_id);
        } elseif ($this->message->side_chat_id) {
            $channels[] = new PrivateChannel('sidechat.'.$this->message->side_chat_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'MessagePinToggled';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'pinned' => $this->message->isPinned(),
            'message' => (new MessageResource($this->message))->resolve(),
        ];
    }
}
