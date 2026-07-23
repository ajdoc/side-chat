<?php

namespace App\Events;

use App\Models\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The owner changed what this call plays when somebody comes or goes.
 *
 * On the container's stream rather than the call's presence channel, and that's the point:
 * the people who most need this are the ones *not* in the call yet. An effect has to be in
 * your browser before the door opens, so it's pushed to everyone who might walk through it
 * — the people already talking pick it up here too, without the owner having to be in the
 * room to tell them.
 *
 * Carries the whole payload rather than the one row that changed, so a client that missed
 * an event still converges — same reasoning as VoiceStateUpdated.
 */
class VoiceEffectsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param  array<string, mixed>  $effects */
    public function __construct(public Channel $channel, public array $effects)
    {
        $this->channel->loadMissing('server', 'conversation');
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $container = $this->channel->container();

        return $container ? [$container->broadcastChannel()] : [];
    }

    public function broadcastAs(): string
    {
        return 'VoiceEffectsUpdated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'channel_id' => $this->channel->id,
            'effects' => $this->effects,
        ];
    }
}
