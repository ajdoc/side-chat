<?php

namespace App\Events;

use App\Models\Channel;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody has just put new sender keys in this channel's post box.
 *
 * A nudge, not a payload. Clients fetch their own inbox in response — which is the only way
 * they *could* learn anything from it, since every blob in there is sealed to one specific
 * device and would be meaningless to the rest of the channel.
 *
 * Without this, key distribution only ever reached clients that happened to open the channel
 * afterwards. A device sitting with the timeline already on screen would receive the messages
 * but not the key, and draw "can't read this" until somebody reloaded — which reads as the
 * encryption being broken rather than as a race, and is exactly what a new device joining a
 * live conversation walks straight into.
 *
 * Carries no key material and names no recipient. The epoch is here only so a client can skip
 * the fetch when it already holds every chain for that era.
 */
class SenderKeysDistributed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Channel $channel,
        public int $epoch,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(Message::streamNameFor($this->channel->id, null, null))];
    }

    public function broadcastAs(): string
    {
        return 'SenderKeysDistributed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['channel_id' => $this->channel->id, 'epoch' => $this->epoch];
    }
}
