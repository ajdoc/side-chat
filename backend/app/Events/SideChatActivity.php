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
 * Something changed a side chat's living-object card: a new message, someone joined, a
 * decision was recorded. Goes to *two* streams — the parent channel (so the card in the
 * timeline stays live even for people who never opened it) and the side chat itself (so an
 * open panel updates its header and member list).
 *
 * Ships the whole card resource rather than a delta, so a client that missed an earlier
 * event still converges on the right counts and roster.
 */
class SideChatActivity implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public SideChat $sideChat)
    {
        app(SideChatService::class)->loadForDisplay($this->sideChat);
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('channel.'.$this->sideChat->channel_id),
            new PrivateChannel('sidechat.'.$this->sideChat->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'SideChatActivity';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return (new SideChatResource($this->sideChat))->resolve();
    }
}
