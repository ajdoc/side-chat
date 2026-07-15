<?php

namespace App\Actions\Voice;

use App\DTOs\Voice\UpdateVoiceStateData;
use App\Events\VoiceStateUpdated;
use App\Models\Channel;
use App\Models\User;
use App\Models\VoiceParticipant;

final class UpdateVoiceStateAction
{
    /**
     * Publish a change to your own mic/screen state, and count as a heartbeat while we're
     * here. Returns null if you aren't in the channel — a state update from someone who
     * has already left is stale, not an error worth raising.
     */
    public function handle(Channel $channel, User $user, UpdateVoiceStateData $data): ?VoiceParticipant
    {
        $participant = VoiceParticipant::query()
            ->where('channel_id', $channel->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $participant) {
            return null;
        }

        $changes = $data->changes();

        $participant->update($changes + ['last_seen_at' => now()]);

        // A heartbeat that changed nothing is not news. Only a real change goes out.
        if ($participant->wasChanged(array_keys($changes))) {
            broadcast(new VoiceStateUpdated($channel));
        }

        return $participant->load('user');
    }
}
