<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A side chat post was deleted.
 *
 * Two streams, like {@link SideChatActivity}: the parent channel, so the forum list and
 * the card on the origin message both drop it, and the side chat's own stream, so anyone
 * with the panel open is told rather than left staring at a room that no longer exists.
 *
 * Ids only — there's nothing left to serialise.
 */
class SideChatDeleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $sideChatId,
        public int $channelId,
        public ?int $messageId,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('channel.'.$this->channelId),
            new PrivateChannel('sidechat.'.$this->sideChatId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'SideChatDeleted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['side_chat_id' => $this->sideChatId, 'channel_id' => $this->channelId, 'message_id' => $this->messageId];
    }
}
