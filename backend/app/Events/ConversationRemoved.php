<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A chat is gone *for you* — you left a group (possibly from another tab).
 *
 * Goes to `user.{id}` for the same reason ConversationCreated does, from the other end:
 * you've just stopped being a member, so `conversation.{id}` is precisely the stream you
 * are no longer allowed to hear.
 */
class ConversationRemoved implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $conversationId, public int $userId) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'ConversationRemoved';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId];
    }
}
