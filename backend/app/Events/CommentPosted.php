<?php

namespace App\Events;

use App\Models\Message;
use App\Services\CommentService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A comment was added or removed. Carries the message's *whole* comment summary rather
 * than the delta — same reasoning as ReactionToggled: a client that missed an event still
 * converges on the right chips and counts.
 */
class CommentPosted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    /**
     * Delivered to the one stream the commented-on message lives on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->message->streamName())];
    }

    public function broadcastAs(): string
    {
        return 'CommentPosted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->message->id,
            'comments' => app(CommentService::class)->summarize($this->message),
        ];
    }
}
