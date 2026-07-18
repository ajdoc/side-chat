<?php

namespace App\Events;

use App\Http\Resources\WhiteboardStrokeResource;
use App\Models\WhiteboardStroke;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A stroke was committed to a whiteboard. Broadcast on the surface's own stream (a side
 * chat's or a channel's, whichever owns the stroke) so every open board converges —
 * including someone who was mid-draw and only saw the whispered preview, and anyone who
 * wasn't watching the live drag at all.
 *
 * Only the durable commit comes through here; the live drag rides over whispers and never
 * touches Laravel. The drawer's own client reconciles its optimistic copy by `client_id`.
 */
class WhiteboardStrokeAdded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public WhiteboardStroke $stroke)
    {
        $this->stroke->loadMissing('user');
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->stroke->streamName())];
    }

    public function broadcastAs(): string
    {
        return 'WhiteboardStrokeAdded';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return (new WhiteboardStrokeResource($this->stroke))->resolve();
    }
}
