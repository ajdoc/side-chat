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
            // Null on a container, set on a discussion. The sidebar decides trunk-or-branch on
            // this one field.
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'type' => $this->type,
            'position' => $this->position,
            // Restricted to an allow-list rather than open to the whole server.
            'is_private' => (bool) $this->is_private,
            // Whether messages sent *now* are encrypted, and which key era they belong to.
            // Both, always, because a client that knew only the flag would have to ask which
            // epoch to encrypt under, and it needs to know before the next keystroke.
            'encrypted' => (bool) $this->encrypted,
            'encryption_epoch' => (int) $this->encryption_epoch,
            // Only ever loaded for the staff editing the access settings — never on the
            // sidebar listing, and never on a broadcast (see ChannelAccessUpdated).
            'member_ids' => $this->whenLoaded('allowedMembers', fn () => $this->allowedMembers->pluck('id')),
            // Set by ChannelService when the list is fetched for a specific user; absent
            // wherever a channel is serialised without one (e.g. straight after creating it).
            'unread_count' => $this->whenNotNull($this->unread_count),
            // Which of this channel's discussions *you* land in. A preference, not a setting:
            // it is read from your own row and means nothing to anybody else.
            'default_child_id' => $this->whenNotNull($this->default_child_id),
            // Your override for this place, and null when you haven't set one — which is not
            // the same as 'all' and must survive the round trip as null, or the sidebar would
            // show every untouched channel as explicitly pinned. See NotificationPolicy.
            'notify_level' => $this->whenNotNull($this->notify_level),
            'muted_until' => $this->whenNotNull($this->muted_until),
            // A container's discussions, when the listing loaded them. Absent — not empty —
            // wherever a channel is serialised on its own, so the sidebar can tell "this channel
            // has no discussions" (impossible) from "you didn't ask for them" (routine).
            'discussions' => ChannelResource::collection($this->whenLoaded('discussions')),
            // Only the discussion directory counts these — how much has been said in here, and
            // when it was last said. Absent everywhere else rather than zero, so a row that was
            // never counted can't be drawn as an empty one.
            'message_count' => $this->whenNotNull($this->messages_count),
            // Gated on the *count* rather than on itself: a discussion nobody has posted in has
            // no last message, and that null is a fact the directory draws ("No messages yet"),
            // not a field to leave out.
            'last_message_at' => $this->when($this->messages_count !== null, fn () => $this->messages_max_created_at),
            'created_at' => $this->created_at,
        ];
    }
}
