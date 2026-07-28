<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChannelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'server_id' => $this->server_id,
            'name' => $this->name,
            'type' => $this->type,
            'position' => $this->position,
            // Restricted to an allow-list rather than open to the whole server.
            'is_private' => (bool) $this->is_private,
            // Only ever loaded for the staff editing the access settings — never on the
            // sidebar listing, and never on a broadcast (see ChannelAccessUpdated).
            'member_ids' => $this->whenLoaded('allowedMembers', fn () => $this->allowedMembers->pluck('id')),
            // Set by ChannelService when the list is fetched for a specific user; absent
            // wherever a channel is serialised without one (e.g. straight after creating it).
            'unread_count' => $this->whenNotNull($this->unread_count),
            'created_at' => $this->created_at,
        ];
    }
}
