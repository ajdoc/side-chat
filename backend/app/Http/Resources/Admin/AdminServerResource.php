<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A server in the admin list: who runs it, how big it is, and what's inside.
 *
 * The channel list rides along only when it's been loaded — the index shows a count and the
 * detail view shows the channels themselves, and one resource serves both.
 */
class AdminServerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'invite_code' => $this->invite_code,
            'discussion_creation' => $this->discussion_creation,
            'sfu_enabled' => (bool) $this->sfu_enabled,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner?->only(['id', 'name', 'email', 'avatar'])),
            'members_count' => $this->whenCounted('members'),
            'channels_count' => $this->whenCounted('channels'),
            'channels' => AdminChannelResource::collection($this->whenLoaded('channels')),
            'created_at' => $this->created_at,
        ];
    }
}
