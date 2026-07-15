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
            'joined_at' => $this->created_at,
        ];
    }
}
