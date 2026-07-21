<?php

namespace App\Events;

use App\Contracts\MessageContainer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody's *public* name in a place changed.
 *
 * Public only, and that's the whole reason this event can exist at all: a private alias is
 * a fact about one viewer, and a broadcast has no one viewer to be right for — the same
 * payload would land on everybody. The person who set a private alias is the only person
 * it affects, and they already know.
 *
 * A null nickname means it was cleared and the person is back to their account name.
 *
 * This goes to the place's own stream, which members only hold open while they're actually
 * looking at it. A rename you weren't there for isn't lost: the client refetches the whole
 * map when it opens a place, so anyone who was away gets it on their way back in.
 */
class NicknameUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public MessageContainer $place,
        public int $userId,
        public ?string $nickname,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [$this->place->broadcastChannel()];
    }

    public function broadcastAs(): string
    {
        return 'NicknameUpdated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['user_id' => $this->userId, 'nickname' => $this->nickname];
    }
}
