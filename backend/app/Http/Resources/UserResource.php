<?php

namespace App\Http\Resources;

use App\Support\SideSpace\Avatars;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar,
            // Drives the BOT badge next to the name. Always present rather than merged in
            // only for bots: the client branches on it, and an absent key would read as false
            // in some places and as "not loaded" in others.
            'is_bot' => (bool) $this->is_bot,
            'provider' => $this->provider,
            'theme_mode' => $this->theme_mode,
            'theme_color' => $this->theme_color,
            // Yours only. Unlike the theme, which shows up on everyone you can see, what
            // somebody chose to be notified about is nobody else's business — and this
            // resource serialises every message author in the room.
            $this->mergeWhen($request->user()?->getKey() === $this->id, fn () => [
                'notify_channel_default' => $this->notify_channel_default,
                'notify_dm_default' => $this->notify_dm_default,
                'push_enabled' => (bool) $this->push_enabled,
            ]),
            // How this person is drawn in a Side Space. Always a complete look, never null:
            // every client draws a sprite for everybody, so "hasn't chosen" has to arrive as
            // something drawable rather than as an absence each of them handles differently.
            'space_avatar' => Avatars::normaliseLook($this->space_avatar),
            'space_pet' => $this->space_pet,
            // The line over their head, or null for nobody shouting anything.
            'space_shout' => $this->space_shout,
            'created_at' => $this->created_at,
        ];
    }
}
