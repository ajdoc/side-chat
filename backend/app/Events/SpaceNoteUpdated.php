<?php

namespace App\Events;

use App\Http\Resources\SpaceNoteResource;
use App\Models\SpaceNote;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A Side Space note was saved. Broadcast on the surface's own stream (a side chat's or a
 * channel's) so every open Notes tab converges on the latest body. The saver's own client is
 * skipped with `->toOthers()` (it already has the text it just typed), the same way the
 * board's whisper layer skips the drawer.
 */
class SpaceNoteUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public SpaceNote $note)
    {
        $this->note->loadMissing('editor');
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->note->streamName())];
    }

    public function broadcastAs(): string
    {
        return 'SpaceNoteUpdated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return (new SpaceNoteResource($this->note))->resolve();
    }
}
