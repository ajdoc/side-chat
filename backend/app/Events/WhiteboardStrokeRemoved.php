<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A stroke was erased from a whiteboard (undo, or the eraser catching an object). Carries
 * only the id — everyone else just drops that stroke from their board. The stream name
 * travels alongside because the stroke row is already gone by the time this is sent.
 */
class WhiteboardStrokeRemoved implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $stream, public int $strokeId) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->stream)];
    }

    public function broadcastAs(): string
    {
        return 'WhiteboardStrokeRemoved';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['id' => $this->strokeId];
    }
}
