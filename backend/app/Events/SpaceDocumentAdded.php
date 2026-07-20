<?php

namespace App\Events;

use App\Http\Resources\SpaceDocumentResource;
use App\Models\SpaceDocument;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A document was uploaded to a Side Space's Docs app. Broadcast on the surface's own stream
 * so every open Docs list gains the file at once. The uploader skips its own echo via
 * `->toOthers()`.
 */
class SpaceDocumentAdded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public SpaceDocument $document)
    {
        $this->document->loadMissing('user');
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->document->streamName())];
    }

    public function broadcastAs(): string
    {
        return 'SpaceDocumentAdded';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return (new SpaceDocumentResource($this->document))->resolve();
    }
}
