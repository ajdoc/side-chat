<?php

namespace App\Events;

use App\Http\Resources\VoiceParticipantResource;
use App\Models\Channel;
use App\Services\VoiceService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Someone joined, left, muted themselves or started sharing their screen.
 *
 * Goes out on the *container's* stream (`server.{id}` or `conversation.{id}`), not the
 * voice channel's, because its whole audience is the people who aren't in the call: it's
 * what puts the little row of faces under a voice channel in the sidebar, and the "2
 * people are in this call" line on a chat you haven't opened. (The people actually in the
 * call learn all of this over the presence channel, far sooner.)
 *
 * Carries the channel's whole roster rather than a delta, so a client that missed an
 * event still converges — same reasoning as ReactionToggled.
 */
class VoiceStateUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Channel $channel)
    {
        $this->channel->loadMissing('server', 'conversation');
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $container = $this->channel->container();

        return $container ? [$container->broadcastChannel()] : [];
    }

    public function broadcastAs(): string
    {
        return 'VoiceStateUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $participants = app(VoiceService::class)->participants($this->channel);

        return [
            'channel_id' => $this->channel->id,
            'participants' => VoiceParticipantResource::collection($participants)->resolve(),
        ];
    }
}
