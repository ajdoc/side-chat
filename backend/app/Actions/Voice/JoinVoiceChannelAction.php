<?php

namespace App\Actions\Voice;

use App\Events\VoiceStateUpdated;
use App\Models\Channel;
use App\Models\User;
use App\Models\VoiceParticipant;
use App\Services\CallService;

final class JoinVoiceChannelAction
{
    public function __construct(private readonly CallService $calls) {}

    /**
     * Take a seat in a call.
     *
     * Idempotent by way of the unique (channel, user) index: rejoining — which happens
     * on a reconnect, or a second tab — refreshes the existing row instead of creating a
     * duplicate. A user can only be in one channel at a time, so joining one leaves any
     * other first, and the channel they left needs telling too.
     *
     * In a DM or group chat this is also where a call *begins*. Walking into an empty room
     * is what makes you the caller and what makes everyone else's phone ring — so joining
     * is the only "start a call" endpoint there is, and there's no second source of truth
     * about whether a call is happening to drift out of sync with who's actually in it.
     * See CallService.
     */
    public function handle(Channel $channel, User $user): VoiceParticipant
    {
        $this->leaveOtherChannels($channel, $user);

        // Counted before the insert, because afterwards there is no way left to ask — and
        // "was the room empty" is the whole difference between placing a call and joining
        // one already in progress.
        $othersBefore = $channel->voiceParticipants()
            ->alive()
            ->where('user_id', '!=', $user->id)
            ->count();

        $participant = VoiceParticipant::updateOrCreate(
            ['channel_id' => $channel->id, 'user_id' => $user->id],
            [
                // A fresh join is an un-muted, un-sharing one — never inherit the state a
                // previous session happened to die in.
                'muted' => false,
                'deafened' => false,
                'screen_sharing' => false,
                'camera_on' => false,
                'audio_sharing' => false,
                'last_seen_at' => now(),
            ],
        );

        broadcast(new VoiceStateUpdated($channel));

        $this->calls->afterJoin($channel, $user, $othersBefore);

        return $participant->load('user');
    }

    /** Hopping from #general to #gaming has to empty your seat in #general. */
    private function leaveOtherChannels(Channel $channel, User $user): void
    {
        $others = VoiceParticipant::query()
            ->with('channel.server', 'channel.conversation')
            ->where('user_id', $user->id)
            ->where('channel_id', '!=', $channel->id)
            ->get();

        foreach ($others as $participant) {
            $participant->delete();

            if (! $participant->channel) {
                continue;
            }

            broadcast(new VoiceStateUpdated($participant->channel));

            // Walking out of a chat's call to answer another one still ends the first one,
            // if you were the last person left in it.
            $this->calls->afterLeave(
                $participant->channel,
                $user,
                $participant->channel->voiceParticipants()->alive()->count(),
            );
        }
    }
}
