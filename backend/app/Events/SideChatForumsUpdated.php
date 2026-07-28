<?php

namespace App\Events;

use App\Models\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A channel's forum list changed — one was created, renamed, reordered or deleted.
 *
 * Ships the ids and names rather than the resource, and deliberately not `can_manage`: that
 * answer is per-viewer, and a broadcast has no viewer. The client keeps whatever it was
 * told when it fetched, which is the same trick SideChatResource plays with rosters.
 */
class SideChatForumsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Channel $channel) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('channel.'.$this->channel->id)];
    }

    public function broadcastAs(): string
    {
        return 'SideChatForumsUpdated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'channel_id' => $this->channel->id,
            'forums' => $this->channel->sideChatForums()->get()
                ->map(fn ($forum) => [
                    'id' => $forum->id,
                    'channel_id' => $forum->channel_id,
                    'name' => $forum->name,
                    'position' => $forum->position,
                ])
                ->all(),
        ];
    }
}
