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
 * A stroke's geometry changed in place — a text label or sticky note was dragged or resized.
 * Broadcast on the surface's own stream so every open board moves the mark to match. Clients
 * replace the stroke by id; the live drag itself rides over whispers and never comes here.
 */
class WhiteboardStrokeUpdated implements ShouldBroadcastNow
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
        return 'WhiteboardStrokeUpdated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return (new WhiteboardStrokeResource($this->stroke))->resolve();
    }
}
