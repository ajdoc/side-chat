<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VoiceParticipantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'channel_id' => $this->channel_id,
            'user' => new UserResource($this->whenLoaded('user')),
            // Self-reported, and the same for every viewer. How loud this person is *for
            // you*, and whether you've muted them, is yours alone and never comes from here.
            'muted' => $this->muted,
            'deafened' => $this->deafened,
            'screen_sharing' => $this->screen_sharing,
            'camera_on' => $this->camera_on,
            // Sound without a picture — see the audio-share migration. Distinct from
            // screen_sharing so nothing offers to 'watch' a screen that isn't coming.
            'audio_sharing' => $this->audio_sharing,
            // Where this person was last known to be standing, in a Side Space; null everywhere
            // else. Not the live position — that's whispered — but it's what lets the room be
            // drawn correctly the instant you walk in, before anybody's first whisper arrives,
            // and it's what puts *you* back where you were after a reload.
            'x' => $this->x,
            'y' => $this->y,
            'facing' => $this->facing,
            'joined_at' => $this->created_at,
        ];
    }
}
