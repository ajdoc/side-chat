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
 * A moderator disconnected you from a call.
 *
 * Sent to the *removed* person's personal stream rather than the call's presence channel,
 * because it has to reach them wherever they've wandered off to: a call outlives the page
 * it started on, so they may be reading a text channel with the mesh still up. Their
 * client tears the connections down on receipt. Everyone else finds out the ordinary way —
 * the removed socket leaves the presence channel and `leaving` fires — so this event is
 * for the one person the presence channel can't tell "it was *your* seat that emptied".
 */
class VoiceParticipantDisconnected implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Channel $channel, public User $target) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->target->id)];
    }

    public function broadcastAs(): string
    {
        return 'VoiceParticipantDisconnected';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['channel_id' => $this->channel->id];
    }
}
