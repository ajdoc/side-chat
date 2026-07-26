<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A surface's Side Desk tab strip changed — an app was added, removed or reordered.
 *
 * Broadcast because the list is shared: adding the Calendar tab in a channel adds it for
 * everyone in that channel, and a strip that only updated on reload would leave one person
 * saying "it's on the Calendar tab" to people who can't see one.
 *
 * @param  array<int, string>  $apps
 */
class DeskAppsSaved implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $streamName, public array $apps) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->streamName)];
    }

    public function broadcastAs(): string
    {
        return 'DeskAppsSaved';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['apps' => $this->apps];
    }
}
