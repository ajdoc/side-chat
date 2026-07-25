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
            'provider' => $this->provider,
            'theme_mode' => $this->theme_mode,
            'theme_color' => $this->theme_color,
            // How this person is drawn in a Side Space. Always a complete look, never null:
            // every client draws a sprite for everybody, so "hasn't chosen" has to arrive as
            // something drawable rather than as an absence each of them handles differently.
            'space_avatar' => Avatars::normaliseLook($this->space_avatar),
            'space_pet' => $this->space_pet,
            'created_at' => $this->created_at,
        ];
    }
}
