<?php

namespace App\Events;

use App\Models\SideSpaceMap;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The room was rebuilt — go and read it again.
 *
 * A notification, not the map. It used to carry the whole `SideSpaceMapResource`, and for a
 * furnished 80x80 room that is comfortably past Reverb's `max_message_size` (10KB by default):
 * the grid alone is ~6.5KB of tile rows, before a thousand pieces of furniture, 50 zones and a
 * keyholder list per locked door. Over that ceiling the websocket server rejects the frame — so
 * the one message this whole feature depends on, "the collision grid changed", is the message
 * that goes missing precisely in the rooms people have built the most in.
 *
 * So the wire carries an identifier and a version, and everybody standing in the room fetches
 * the map over HTTP, where a large body is unremarkable. Slower by one round trip, and that is
 * affordable here: rebuilding a room is a rare, deliberate act, and nothing at 60fps waits on it
 * — walking is whispered peer-to-peer and never touches this.
 *
 * `updated_at` travels so a client can tell a new version from one it already holds — a client
 * whose own PUT response *was* this version skips the refetch entirely.
 */
class SideSpaceMapUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public SideSpaceMap $map) {}

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
        return [
            'id' => $this->map->id,
            'channel_id' => $this->map->channel_id,
            /*
             * Which of the channel's rooms was rebuilt.
             *
             * A Side Space holds several maps but one broadcast channel, so this event now
             * reaches people who are standing in a *different* room of the same building. They
             * must not refetch: they would be told about a grid they aren't on, and the one
             * they are on would be replaced by it. The slug is what lets each listener decide
             * whether the news is about the floor under its own feet.
             */
            'slug' => $this->map->slug,
            'updated_at' => $this->map->updated_at?->toISOString(),
        ];
    }
}
