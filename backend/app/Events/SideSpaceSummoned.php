<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Staff told people in a Side Space to follow them, or let them go again.
 *
 * The one thing in the room that moves somebody else's avatar and does *not* ask them first.
 * That is why it is an event over HTTP rather than a whisper, which everything else about
 * movement is: a whisper is written by a client and believed by every other client, so a
 * summon carried on one would be a summon any member could forge — and forging this one drags
 * the whole room around. Here the gate is {@see \App\Http\Requests\SideSpace\SummonSpaceRequest},
 * checked on the server, and what reaches the room is a fact rather than a claim.
 *
 * Only *starting* and *stopping* travel this way. The following itself is the follower's own
 * client walking after the leader's whispered position, exactly as holding hands works and for
 * the same reason: nobody's coordinates are ever written by anybody but their owner.
 *
 * A null `user_ids` means everybody in the room — the whole point of the feature is usually
 * "everyone over here", and enumerating a roster the server would have to fetch to answer a
 * question the client can answer from presence is work for nothing.
 */
class SideSpaceSummoned implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param array<int, int>|null $userIds */
    public function __construct(
        public int $channelId,
        public User $leader,
        public ?array $userIds,
        public bool $following,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("channel.{$this->channelId}")];
    }

    public function broadcastAs(): string
    {
        return 'SideSpaceSummoned';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'channel_id' => $this->channelId,
            'leader_id' => $this->leader->id,
            // The name rides along so a follower can be told who has them without a lookup —
            // the same reason a position whisper carries one.
            'leader_name' => $this->leader->name,
            'user_ids' => $this->userIds,
            'following' => $this->following,
        ];
    }
}
