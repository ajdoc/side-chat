<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A document was removed from a Side Desk's Docs app. Only the surface's stream and the id
 * travel — every open list drops it. Mirrors {@see WhiteboardStrokeRemoved}.
 */
class SpaceDocumentRemoved implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $streamName, public int $documentId) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->streamName)];
    }

    public function broadcastAs(): string
    {
        return 'SpaceDocumentRemoved';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['id' => $this->documentId];
    }
}
