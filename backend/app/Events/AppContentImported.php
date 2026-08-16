<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A channel's app just gained a pile of content from somewhere else.
 *
 * ## Why one event rather than the app's own
 *
 * Every app already broadcasts its rows one at a time — `CalendarEventSaved`, a `TrackerChanged`
 * per task. Firing those for an import would send eighty-four events for one gesture, and each
 * carries a full resource: the same flood-and-size problem that made the kanban board's own
 * broadcast fail in production, arriving as a hundred small messages instead of one too-large
 * one.
 *
 * So an import says **that** it happened and nothing about what arrived. It carries an app id
 * and a count — a payload of two short fields, whatever the import's size — and clients re-read
 * the app they already know how to read. The rule this follows is the one the board learned:
 * never put an unbounded collection on the wire.
 *
 * The count is for the notice ("24 events arrived"), not for reconciliation. Nothing should
 * compute state from it.
 */
class AppContentImported implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $streamName,
        public string $app,
        public int $count,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->streamName)];
    }

    public function broadcastAs(): string
    {
        return 'AppContentImported';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['app' => $this->app, 'count' => $this->count];
    }
}
