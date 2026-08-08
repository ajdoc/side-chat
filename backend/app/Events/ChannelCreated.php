<?php

namespace App\Events;

use App\Models\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A channel, or a discussion inside one, has just been made.
 *
 * On the *server* stream, like ChannelUpdated and ChannelDeleted: the sidebar is where a new
 * channel has to appear, and everybody has the sidebar open while only the people already
 * inside a channel are on `channel.{id}`.
 *
 * Carries ids and nothing else — deliberately, and this is the important part. A discussion can
 * be created inside a private channel, and a payload naming it would tell the whole server that
 * a room they can't see exists and what it's called. So this is a nudge to *ask again* rather
 * than a delivery: the channel list is answered per viewer, so refetching makes the new row
 * appear for exactly the people allowed to see it and for nobody else. Same reasoning, and the
 * same shape, as ChannelAccessUpdated.
 */
class ChannelCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Channel $channel) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('server.'.$this->channel->server_id)];
    }

    public function broadcastAs(): string
    {
        return 'ChannelCreated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'channel_id' => $this->channel->id,
            // Null for a channel, set for a discussion — enough for a client to know whether a
            // branch it is drawing just gained a row, without naming either.
            'parent_id' => $this->channel->parent_id,
            'server_id' => $this->channel->server_id,
        ];
    }
}
