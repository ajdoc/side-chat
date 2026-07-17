<?php

namespace App\Events;

use App\Http\Resources\SideChatResource;
use App\Models\SideChat;
use App\Services\SideChatService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A side chat was spun up. Announced on the parent channel so its living-object card
 * appears in the timeline (on the origin message) for everyone at once.
 */
class SideChatCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public SideChat $sideChat)
    {
        app(SideChatService::class)->loadForDisplay($this->sideChat);
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('channel.'.$this->sideChat->channel_id)];
    }

    public function broadcastAs(): string
    {
        return 'SideChatCreated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return (new SideChatResource($this->sideChat))->resolve();
    }
}
