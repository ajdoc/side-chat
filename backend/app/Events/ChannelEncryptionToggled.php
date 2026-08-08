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
 * Encryption has been turned on or off in a channel.
 *
 * Goes out on the channel's own message stream rather than to the whole server, because it
 * is addressed to the people looking at the timeline: their composer has to change state
 * mid-conversation, and a client that kept sending plaintext into a channel that had just
 * been locked would be the worst possible failure of this feature.
 *
 * The epoch rides along because it is what the client encrypts *under*. A client that
 * learned encryption was on but not which era it was in would have to ask, and the window
 * between the two answers is a window where it doesn't know how to send.
 */
class ChannelEncryptionToggled implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Channel $channel) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(Message::streamNameFor($this->channel->id, null, null))];
    }

    public function broadcastAs(): string
    {
        return 'ChannelEncryptionToggled';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'channel_id' => $this->channel->id,
            'encrypted' => $this->channel->isEncrypted(),
            'encryption_epoch' => (int) $this->channel->encryption_epoch,
            'toggled_by' => $this->channel->encryption_toggled_by,
        ];
    }
}
