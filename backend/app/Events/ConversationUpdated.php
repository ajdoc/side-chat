<?php

namespace App\Events;

use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A group was renamed, or its member list changed. Everyone still in it is listening. */
class ConversationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Conversation $conversation)
    {
        $this->conversation->loadMissing('members', 'channel');
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [$this->conversation->broadcastChannel()];
    }

    public function broadcastAs(): string
    {
        return 'ConversationUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return (new ConversationResource($this->conversation))->resolve();
    }
}
