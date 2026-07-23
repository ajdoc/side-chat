<?php

namespace App\Actions\Voice;

use App\Events\VoiceMuteEnforced;
use App\Events\VoiceStateUpdated;
use App\Models\Channel;
use App\Models\User;
use App\Models\VoiceParticipant;

final class MuteVoiceParticipantAction
{
    /**
     * Mute or unmute somebody else in a call.
     *
     * Two things have to happen and neither can stand in for the other. The row is written
     * here so the sidebar and everyone's roster are right immediately — the target's browser
     * might be slow, or asleep, and a mute icon that waits on a round trip through a third
     * machine looks broken. And the target is told, because the microphone itself is on
     * *their* machine and this server cannot reach it; see VoiceMuteEnforced.
     *
     * Their client republishes its state on receipt, which lands back here as an ordinary
     * state update and confirms what we just wrote. Writing the same value twice is free,
     * and the second write is the one that's actually true.
     *
     * Returns false if they aren't in the call — a stale click on someone who has already
     * left, which is nothing to raise an error over.
     */
    public function handle(Channel $channel, int $targetUserId, bool $muted): bool
    {
        $participant = VoiceParticipant::query()
            ->with('user')
            ->where('channel_id', $channel->id)
            ->where('user_id', $targetUserId)
            ->alive()
            ->first();

        $target = $participant?->user;

        if (! $participant || ! $target instanceof User) {
            return false;
        }

        $participant->update(['muted' => $muted]);

        if ($participant->wasChanged('muted')) {
            broadcast(new VoiceStateUpdated($channel));
        }

        // Sent even when the row didn't change: the row is what the *server* last heard,
        // and the whole point of this call is that the owner disagrees with it. A client
        // that has drifted out of step is exactly the one that needs telling.
        broadcast(new VoiceMuteEnforced($channel, $target, $muted));

        return true;
    }
}
