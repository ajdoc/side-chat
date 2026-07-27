<?php

namespace App\Events;

use App\Http\Resources\SideSpaceMapResource;
use App\Models\SideSpaceMap;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The room was rebuilt. Broadcast on the channel's own stream so everybody standing in it gets
 * the new geometry — including, importantly, the collision map, so a wall that has just been
 * painted starts stopping people rather than only looking like it should.
 *
 * Not sent `->toOthers()`: unlike a note, where the editor already has the text they typed, the
 * saver benefits from converging on exactly the map the server stored.
 */
class SideSpaceMapUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public SideSpaceMap $map)
    {
        /*
         * Rooms and locks as well as the editor, and this is load-bearing rather than tidy.
         *
         * The resource writes them with `whenLoaded`, so an unloaded relation isn't sent as
         * empty — it's absent. A client applying a broadcast that had left them out would end up
         * holding a map with no locks on it, and would open every door in the room. Loading them
         * here is what makes "the map changed" and "the map, whole" the same message.
         */
        $this->map->loadMissing('editor', 'rooms.owner', 'locks.creator');
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("channel.{$this->map->channel_id}")];
    }

    public function broadcastAs(): string
    {
        return 'SideSpaceMapUpdated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return (new SideSpaceMapResource($this->map))->resolve();
    }
}
