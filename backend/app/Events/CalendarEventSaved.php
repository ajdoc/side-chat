<?php

namespace App\Events;

use App\Http\Resources\CalendarEventResource;
use App\Models\CalendarEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A calendar entry was created or changed. One event covers both — the client upserts by `id`,
 * exactly as {@see CanvasItemSaved} does.
 *
 * Broadcast on the surface's own stream, which is what keeps the Calendar *app* and the Calendar
 * *canvas card* in step: they're two views subscribed to one stream, so neither needs to know
 * the other exists. The actor skips its own echo via `->toOthers()`.
 */
class CalendarEventSaved implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public CalendarEvent $event)
    {
        $this->event->loadMissing('user');
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->event->streamName())];
    }

    public function broadcastAs(): string
    {
        return 'CalendarEventSaved';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return (new CalendarEventResource($this->event))->resolve();
    }
}
