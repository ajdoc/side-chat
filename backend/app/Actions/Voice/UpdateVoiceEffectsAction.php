<?php

namespace App\Actions\Voice;

use App\DTOs\Voice\UpdateVoiceEffectsData;
use App\Events\VoiceEffectsUpdated;
use App\Models\Channel;
use App\Models\VoiceEffectAssignment;

final class UpdateVoiceEffectsAction
{
    /**
     * Attach an effect to one person in this call — or, with no person named, set the room's
     * default for everybody who hasn't been singled out.
     *
     * Clearing both sides deletes the row rather than storing two nulls. A person with an
     * assignment saying "nothing" and a person with no assignment behave identically today,
     * but they wouldn't the moment the room's default changes underneath them: the empty row
     * would silently start meaning "opted out". Deleting keeps "nothing special about this
     * person" as exactly one state.
     *
     * @return array<string, mixed> the channel's whole effects payload, as everyone will see it
     */
    public function handle(Channel $channel, UpdateVoiceEffectsData $data): array
    {
        $effects = $data->effects();

        if ($data->isForPerson()) {
            $blank = $effects['join_effect'] === null && $effects['leave_effect'] === null;

            if ($blank) {
                VoiceEffectAssignment::query()
                    ->where('channel_id', $channel->id)
                    ->where('user_id', $data->user_id)
                    ->delete();
            } else {
                VoiceEffectAssignment::updateOrCreate(
                    ['channel_id' => $channel->id, 'user_id' => $data->user_id],
                    $effects,
                );
            }
        } else {
            $channel->update($effects);
        }

        $payload = $channel->refresh()->voiceEffects();

        // Everyone who might be in this call, or about to walk into it — see the event.
        broadcast(new VoiceEffectsUpdated($channel, $payload));

        return $payload;
    }
}
