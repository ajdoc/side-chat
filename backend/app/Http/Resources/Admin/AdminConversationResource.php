<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A DM or group chat, from the outside.
 *
 * Note what isn't here: message bodies. A conversation row tells an administrator who is in
 * it and how much traffic it carries, which is what the chats screen needs; reading what was
 * actually said is a separate, deliberate step through the audit endpoint, and one that a
 * per-timeline encryption toggle can refuse outright. See AdminMessageResource.
 */
class AdminConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            // Groups are named; a DM's name is whoever the other person is, which the client
            // builds from `members` because it depends on who's looking.
            'name' => $this->name,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner?->only(['id', 'name', 'avatar'])),
            'members' => $this->whenLoaded(
                'members',
                fn () => $this->members->map->only(['id', 'name', 'email', 'avatar']),
            ),
            'members_count' => $this->whenCounted('members'),
            // The single channel the messages actually live in — the audit view needs its id.
            'channel' => $this->whenLoaded('channel', fn () => $this->channel ? [
                'id' => $this->channel->id,
                'encrypted' => (bool) $this->channel->encrypted,
                'messages_count' => $this->channel->messages_count,
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}
