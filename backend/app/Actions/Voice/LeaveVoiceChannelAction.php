<?php

namespace App\Actions\Voice;

use App\Events\VoiceStateUpdated;
use App\Models\Channel;
use App\Models\User;
use App\Models\VoiceParticipant;
use App\Services\CallService;

final class LeaveVoiceChannelAction
{
    public function __construct(private readonly CallService $calls) {}

    /**
     * Give up your seat. Broadcast only if you actually had one, so the tail of retries
     * and unload beacons a leaving browser tends to fire doesn't turn into a stream of
     * identical events for everyone else.
     *
     * In a chat, being the *last* one out is what ends the call — and writes either
     * "Call ended · 4m 12s" or "Missed call" into the transcript. See CallService.
     */
    public function handle(Channel $channel, User $user): void
    {
        $deleted = VoiceParticipant::query()
            ->where('channel_id', $channel->id)
            ->where('user_id', $user->id)
            ->delete();

        if ($deleted === 0) {
            return;
        }

        broadcast(new VoiceStateUpdated($channel));

        $this->calls->afterLeave(
            $channel,
            $user,
            $channel->voiceParticipants()->alive()->count(),
        );
    }
}
