<?php

namespace App\Events;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The owner moved your microphone for you.
 *
 * The row is already written by the time this goes out, so everyone else's sidebar is
 * correct without it — this exists for the one browser that has to *act*: the mic track
 * lives on their machine and nothing on the server can reach it. So it travels the same
 * road as VoiceParticipantDisconnected, on their personal stream rather than the call's
 * presence channel, because a call outlives the page it started on and they may well be
 * reading something else with the mesh still up.
 *
 * Their client flips the switch and republishes its state as if they'd clicked the button
 * themselves, which is what puts the change in front of the rest of the room.
 */
class VoiceMuteEnforced implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Channel $channel,
        public User $target,
        public bool $muted,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->target->id)];
    }

    public function broadcastAs(): string
    {
        return 'VoiceMuteEnforced';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'channel_id' => $this->channel->id,
            'muted' => $this->muted,
        ];
    }
}
