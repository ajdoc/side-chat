<?php

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Support\Arr;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message)
    {
        $this->message->loadMissing('user', 'replyTo.user', 'attachments', 'reactions.user', 'linkPreviews', 'widget');
    }

    /**
     * Delivered to the one stream the message lives on — side chat, thread, or channel.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->message->streamName())];
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
        $data = (new MessageResource($this->message))->resolve();

        // A widget card's full state (a queue of up to 100 tracks) can blow past Pusher's
        // 10KB per-event limit. Ship only a reference over the socket; the client pulls the
        // fresh state from GET /api/widgets/{id}. HTTP responses still carry the whole thing.
        if (is_array($data['widget'] ?? null)) {
            $data['widget'] = Arr::only($data['widget'], ['id', 'channel_id', 'type']);
        }

        return $data;
    }
}
