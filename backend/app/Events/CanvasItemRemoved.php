<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An Open Canvas card was deleted. Only the surface's stream and the id travel — every open
 * canvas drops the card. Mirrors {@see WhiteboardStrokeRemoved}.
 */
class CanvasItemRemoved implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $streamName, public int $itemId) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->streamName)];
    }

    public function broadcastAs(): string
    {
        return 'CanvasItemRemoved';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['id' => $this->itemId];
    }
}
