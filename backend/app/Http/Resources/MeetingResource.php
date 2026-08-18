<?php

namespace App\Http\Resources;

use App\Models\Meeting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A meeting as its dialog, its link page and its audit list draw it.
 *
 * The `token` is what the link is made of, so it goes only to people who can already reach the
 * meeting — the create response and the room's own listing. The join endpoint answers with the
 * room and never re-issues it.
 *
 * @mixin Meeting
 */
class MeetingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'token' => $this->token,
            'channel_id' => $this->channel_id,
            // Enough for the client to build the path to the room and to say what kind of place
            // it is — the same three facts every other room picker here needs.
            'room' => $this->whenLoaded('channel', fn () => [
                'id' => $this->channel->id,
                'name' => $this->channel->name,
                'type' => $this->channel->type,
                'server_id' => $this->channel->server_id,
                'conversation_id' => $this->channel->conversation_id,
            ]),
            'access' => $this->access,
            // True only where a link *can* admit an outsider: a group conversation's meeting. On
            // a server room the flag is meaningless and the client shouldn't offer it.
            'admits_outsiders' => $this->admitsOutsiders(),
            // Whether somebody with no account may walk in — never true for a server room or an
            // encrypted channel, whatever the setting says. See Meeting::admitsGuests.
            'admits_guests' => $this->admitsGuests(),
            'scheduled_at' => $this->whenLoaded('scheduledEvent', fn () => $this->scheduledEvent?->starts_at?->toIso8601String()),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'creator' => new UserResource($this->whenLoaded('creator')),
            'joins_count' => $this->whenCounted('joins'),
            'created_at' => $this->created_at,
        ];
    }
}
