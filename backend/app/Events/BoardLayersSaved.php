<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A board's layers changed — one was added, renamed, hidden or shown.
 *
 * Broadcast for the same reason {@see DeskAppsSaved} is: layers are a property of the shared
 * board, not of the viewer, so hiding one has to hide it for everybody or "it's on the Sketch
 * layer" becomes untrue for whoever you said it to.
 *
 * @param  array<int, array{name: string, visible: bool}>  $layers
 */
class BoardLayersSaved implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $streamName, public array $layers) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->streamName)];
    }

    public function broadcastAs(): string
    {
        return 'BoardLayersSaved';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['layers' => $this->layers];
    }
}
