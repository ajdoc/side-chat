<?php

namespace App\Events;

use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A chat you're now in appeared — someone opened a DM with you, started a group with you
 * in it, or added you to one.
 *
 * The one event that *can't* go to the place it's about. You are not subscribed to
 * `conversation.{id}` for a conversation you have never heard of, and subscribing you to
 * it is the very thing this event exists to make possible. So it fans out to each
 * recipient's personal `user.{id}` stream instead — see routes/channels.php.
 */
class ConversationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param  array<int, int>|null  $userIds  who to tell; defaults to everyone in it */
    public function __construct(
        public Conversation $conversation,
        public ?array $userIds = null,
    ) {
        $this->conversation->loadMissing('members', 'channel');
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $ids = $this->userIds ?? $this->conversation->memberIds();

        return array_map(fn (int $id) => new PrivateChannel('user.'.$id), $ids);
    }

    public function broadcastAs(): string
    {
        return 'ConversationCreated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return (new ConversationResource($this->conversation))->resolve();
    }
}
