<?php

namespace App\Events;

use App\Http\Resources\UserResource;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * "Not now."
 *
 * Goes to everyone in the conversation, and it has two distinct audiences. The people in
 * the call want to know the ringing stopped and why. The person who declined wants their
 * *other tabs* to stop ringing too — which is the case you only notice when you don't
 * handle it, and then it rings on your phone for another thirty seconds after you've
 * dismissed it on your laptop.
 */
class CallDeclined implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Conversation $conversation, public User $user) {}

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
        return 'CallDeclined';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'user' => (new UserResource($this->user))->resolve(),
        ];
    }
}
