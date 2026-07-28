<?php

namespace App\Events;

use App\Models\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Who may be in a channel has changed.
 *
 * Carries the ids and nothing else — deliberately. It goes to the whole server, because
 * the people who need it most are the ones being *removed*: their sidebar is still showing
 * a channel they can no longer open, and nobody else can tell them. But that same reach is
 * why the payload can't contain the allow-list — it would hand the full membership of a
 * private channel to everyone it was made private from.
 *
 * So this is a nudge, not a diff: every client refetches the channel list, and the list
 * query answers per viewer (see ChannelService::forServer). The channel appears, or stops
 * appearing, for exactly the right people, and no client is ever told about the others.
 */
class ChannelAccessUpdated implements ShouldBroadcastNow
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
        return 'ChannelAccessUpdated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['channel_id' => $this->channel->id, 'server_id' => $this->channel->server_id];
    }
}
