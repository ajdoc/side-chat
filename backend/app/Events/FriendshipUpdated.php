<?php

namespace App\Events;

use App\Http\Resources\UserResource;
use App\Models\Friendship;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A tie between two people changed: asked for, accepted, or blocked.
 *
 * Goes to both of them personally. Neither is subscribed to the other, and the whole point
 * of a friend request is that it arrives on whatever screen you happen to be looking at.
 *
 * The payload carries both people rather than "the other one", because *the other one*
 * depends on which of the two streams this copy landed on and an event has one body. The
 * client picks its side — see useFriends.
 */
class FriendshipUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Friendship $friendship)
    {
        $this->friendship->loadMissing('user', 'friend');
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return $this->friendship->notificationChannels();
    }

    public function broadcastAs(): string
    {
        return 'FriendshipUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->friendship->id,
            'status' => $this->friendship->status,
            'requester_id' => $this->friendship->user_id,
            'addressee_id' => $this->friendship->friend_id,
            'requester' => (new UserResource($this->friendship->user))->resolve(),
            'addressee' => (new UserResource($this->friendship->friend))->resolve(),
            'created_at' => $this->friendship->created_at,
            'updated_at' => $this->friendship->updated_at,
        ];
    }
}
