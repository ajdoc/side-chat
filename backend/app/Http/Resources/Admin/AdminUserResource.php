<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user as an administrator sees them.
 *
 * Separate from UserResource on purpose. That one serialises every message author in every
 * room, so it can only ever carry what's safe for a stranger to read; this one carries
 * standing, the ban and its reason, and counts nobody else has any business seeing. Keeping
 * them apart means nothing here can leak into a timeline by accident.
 */
class AdminUserResource extends JsonResource
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
            'is_bot' => (bool) $this->is_bot,
            'role' => $this->role,
            'provider' => $this->provider,
            'email_verified_at' => $this->email_verified_at,
            'banned' => $this->isBanned(),
            'banned_at' => $this->banned_at,
            'ban_reason' => $this->ban_reason,
            // The admin who issued it, when we still have them. Null covers both "not banned"
            // and "the admin's account is gone" — neither needs distinguishing on the screen.
            'banned_by' => $this->whenLoaded('bannedBy', fn () => $this->bannedBy?->only(['id', 'name'])),
            // Only present on the list endpoint, which asks for them with withCount().
            'servers_count' => $this->whenCounted('servers'),
            'owned_servers_count' => $this->whenCounted('ownedServers'),
            'messages_count' => $this->whenCounted('messages'),
            'created_at' => $this->created_at,
        ];
    }
}
