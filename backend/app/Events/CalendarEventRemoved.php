<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A calendar entry was deleted. Only the surface's stream and the id travel — every open
 * calendar, app or canvas card, drops it. Mirrors {@see CanvasItemRemoved}.
 */
class CalendarEventRemoved implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $streamName, public int $eventId) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->streamName)];
    }

    public function broadcastAs(): string
    {
        return 'CalendarEventRemoved';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['id' => $this->eventId];
    }
}
