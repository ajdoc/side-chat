<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The last person hung up. Stop ringing.
 *
 * Goes to every member's personal stream — including people who never answered, whose
 * phone is the whole reason this event exists. A ring that outlives the call is the most
 * annoying bug this feature could possibly have.
 */
class CallEnded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Conversation $conversation, public bool $answered) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            fn (int $id) => new PrivateChannel('user.'.$id),
            $this->conversation->memberIds(),
        );
    }

    public function broadcastAs(): string
    {
        return 'CallEnded';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            // False means nobody picked up — the client shows "missed", not "ended".
            'answered' => $this->answered,
        ];
    }
}
