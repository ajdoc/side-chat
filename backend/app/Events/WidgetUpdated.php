<?php

namespace App\Events;

use App\Models\Widget;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A widget's state moved — a track started, the queue changed, a card crossed a column.
 *
 * Broadcast on the channel's own stream (widgets are channel-scoped). It carries only a
 * *reference* to the widget, not its state: a music queue can hold up to 100 tracks and
 * the full JSON blows past Pusher's 10KB per-event limit ("Payload too large"). On this
 * signal each client GETs the fresh state from `/api/widgets/{id}` and re-renders every
 * card of it (matched by `widget.id`; there may be several). Broadcasts *now* rather than
 * via the queue, so a listen-along stays in sync.
 */
class WidgetUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Widget $widget) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('channel.'.$this->widget->channel_id)];
    }

    public function broadcastAs(): string
    {
        return 'WidgetUpdated';
    }

    /**
     * Only a reference travels over the socket — the full state can exceed Pusher's 10KB
     * event cap. Clients fetch the fresh state from GET /api/widgets/{id}.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->widget->id,
            'channel_id' => $this->widget->channel_id,
            'type' => $this->widget->type,
        ];
    }
}
