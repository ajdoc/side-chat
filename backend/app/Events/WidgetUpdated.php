<?php

namespace App\Events;

use App\Http\Resources\WidgetResource;
use App\Models\Widget;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A widget's state moved — a track started, the queue changed, a card crossed a column.
 *
 * Broadcast on the channel's own stream (widgets are channel-scoped), carrying the whole
 * fresh state so every card rendering this widget re-renders in place at once. There may
 * be several cards for one widget in the timeline; the client patches all of them by
 * matching `widget.id`. This is the transport that keeps a listen-along in sync, so it
 * broadcasts *now* rather than via the queue.
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

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return (new WidgetResource($this->widget))->resolve();
    }
}
