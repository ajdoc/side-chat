<?php

namespace App\Events;

use App\Models\Friendship;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The tie is gone: declined, cancelled, unfriended or unblocked.
 *
 * One event for all four, because they are the same thing to a client — a row to drop —
 * and the difference between them is only ever visible to the person who pressed the
 * button, who already knows. The row is deleted by the time this is constructed, so it
 * carries plain ids rather than a model.
 */
class FriendshipRemoved implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $friendshipId,
        public int $requesterId,
        public int $addresseeId,
    ) {}

    public static function of(Friendship $friendship): self
    {
        return new self($friendship->id, $friendship->user_id, $friendship->friend_id);
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->requesterId),
            new PrivateChannel('user.'.$this->addresseeId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'FriendshipRemoved';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->friendshipId,
            'requester_id' => $this->requesterId,
            'addressee_id' => $this->addresseeId,
        ];
    }
}
