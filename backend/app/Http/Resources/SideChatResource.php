<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A side chat as a living object — the card in the main timeline and the panel header.
 *
 * `participant_ids` ships instead of a per-viewer `joined` flag for the same reason
 * reactions ship their reactors: this resource is broadcast to everyone (SideChatCreated),
 * and one baked-in flag can't be right for all of them. The client compares the ids to the
 * logged-in user to decide whether to show [Join] or [Open].
 */
class SideChatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'message_id' => $this->message_id,
            'name' => $this->name,
            'creator' => new UserResource($this->whenLoaded('creator')),
            'parent_message' => new MessageResource($this->whenLoaded('parentMessage')),
            // Frozen snapshot of the origin message, so "Started from" outlives its deletion.
            'origin_author' => $this->origin_author,
            'origin_excerpt' => $this->origin_excerpt,
            // The roster, when loaded — for the card's avatar stack and the panel's member list.
            'participants' => UserResource::collection($this->whenLoaded('participants')),
            'participant_ids' => $this->whenLoaded('participants', fn () => $this->participants->pluck('id')),
            // The living-object counters: 👥 💬 📌 ✅
            'participants_count' => $this->whenCounted('participants'),
            'messages_count' => $this->whenCounted('messages'),
            'pinned_count' => $this->when(isset($this->pinned_count), fn () => (int) $this->pinned_count),
            'decisions_count' => $this->when(isset($this->decisions_count), fn () => (int) $this->decisions_count),
            // "Last active 5m ago" — the newest message's timestamp, or the side chat's own
            // creation time when nobody's said anything yet.
            'last_active_at' => $this->messages_max_created_at ?? $this->created_at,
            'created_at' => $this->created_at,
        ];
    }
}
