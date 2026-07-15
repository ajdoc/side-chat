<?php

namespace App\Events;

use App\Http\Resources\ConversationResource;
use App\Http\Resources\UserResource;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody is calling. This is the ring.
 *
 * The difference between a call in a chat and a call in a server's voice channel, in one
 * event. A voice channel is a *place*: it's in the sidebar, it's always there, you walk in
 * when you feel like it and nobody is interrupted. A call in a DM is an *event* aimed at a
 * person, and it is worthless if it doesn't reach them — which means it cannot be sent to
 * the conversation, because the whole point is that they aren't looking at it.
 *
 * So it goes to each recipient's `user.{id}` stream: it arrives whatever they happen to be
 * doing, in whatever channel of whatever server, and even if they have never once opened
 * this conversation.
 *
 * Sent only to the *others*. Ringing your own phone is a bug, not a feature.
 */
class CallStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public User $caller,
    ) {
        $this->conversation->loadMissing('members', 'channel');
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            fn (int $id) => new PrivateChannel('user.'.$id),
            array_values(array_diff($this->conversation->memberIds(), [$this->caller->id])),
        );
    }

    public function broadcastAs(): string
    {
        return 'CallStarted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation' => (new ConversationResource($this->conversation))->resolve(),
            'caller' => (new UserResource($this->caller))->resolve(),
        ];
    }
}
