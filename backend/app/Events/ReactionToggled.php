<?php

namespace App\Events;

use App\Models\Message;
use App\Services\ReactionService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A reaction was added or removed. Carries the message's *whole* reaction summary
 * rather than the delta, so a client that missed an event still converges on the
 * right counts.
 */
class ReactionToggled implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->message->streamName())];
    }

    public function broadcastAs(): string
    {
        return 'ReactionToggled';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->message->id,
            'reactions' => app(ReactionService::class)->summarize($this->message),
        ];
    }
}
