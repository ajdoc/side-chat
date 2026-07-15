<?php

namespace App\Http\Requests\Voice;

use App\Models\Channel;
use App\Services\VoiceService;
use Illuminate\Validation\Validator;

class JoinVoiceChannelRequest extends VoiceChannelRequest
{
    /** @return array<int, callable> */
    public function after(): array
    {
        return array_merge(parent::after(), [
            function (Validator $validator) {
                $channel = $this->route('channel');

                if (! $channel instanceof Channel || $validator->errors()->isNotEmpty()) {
                    return;
                }

                // Already inside? Then you're reconnecting, and a full call must not lock
                // you out of the seat you already hold.
                $inside = $channel->voiceParticipants()
                    ->alive()
                    ->where('user_id', $this->user()?->id)
                    ->exists();

                if (! $inside && app(VoiceService::class)->isFull($channel)) {
                    $validator->errors()->add('channel', sprintf(
                        'This voice channel is full (%d people).',
                        (int) config('webrtc.max_participants'),
                    ));
                }
            },
        ]);
    }
}
