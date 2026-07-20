<?php

namespace App\Events;

use App\Http\Resources\CanvasItemResource;
use App\Models\CanvasItem;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An Open Canvas card was created or changed — added, moved, resized, or edited. One event
 * covers both: the client upserts by `id`. Broadcast on the surface's own stream (a side
 * chat's or a channel's) so every open canvas converges. The actor skips its own echo via
 * `->toOthers()`, keyed by the `X-Socket-ID` header — the same split the board uses.
 */
class CanvasItemSaved implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public CanvasItem $item)
    {
        $this->item->loadMissing('user', 'widget');
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->item->streamName())];
    }

    public function broadcastAs(): string
    {
        return 'CanvasItemSaved';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return (new CanvasItemResource($this->item))->resolve();
    }
}
