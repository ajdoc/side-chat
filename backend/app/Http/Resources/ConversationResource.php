<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A DM or group chat, as the sidebar and the chat page see it.
 *
 * Deliberately viewer-independent — no title, no "is this me", no is_owner. That's not an
 * oversight, it's what makes the same payload safe to *broadcast*: a DM is called "Ana" to
 * you and "Ben" to Ana, so a title baked in here would be wrong for exactly half the
 * people who received it. The client has `members` and knows who it is; it can do that
 * subtraction itself. (Same reasoning as ReactionResource not saying whether *you*
 * reacted.)
 *
 * `unread_count` is the one exception, and it's opt-in: present only when somebody asked
 * for their own list, absent from every broadcast — see whenNotNull.
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            // Null for a DM: it has no name of its own, only the person you're talking to.
            'name' => $this->name,
            'owner_id' => $this->owner_id,
            // The channel behind the chat. Every message, thread, pin, reaction and call
            // endpoint in the app is addressed by this — a chat *is* a channel.
            'channel_id' => $this->whenLoaded('channel', fn () => $this->channel->id),
            'members' => UserResource::collection($this->whenLoaded('members')),
            // Live now, so a chat you aren't looking at can say "call in progress".
            'call_active' => $this->call_started_at !== null,
            'call_started_at' => $this->call_started_at,
            'call_started_by' => $this->call_started_by,
            'unread_count' => $this->whenNotNull($this->unread_count),
            // Set by ConversationService when the list is fetched — it's what the list is
            // sorted by, and what the sidebar shows as "3:42pm" next to the name.
            'last_message_at' => $this->whenNotNull($this->last_message_at ?? null),
            'created_at' => $this->created_at,
        ];
    }
}
