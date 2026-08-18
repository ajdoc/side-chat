<?php

namespace App\Http\Resources;

use App\Models\CalendarEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One calendar entry, as the Calendar app and the Calendar canvas card both draw it.
 *
 * Times go out as ISO-8601 UTC and are rendered in the viewer's own zone — a shared calendar
 * spanning zones has to agree on the instant, not on the wall clock.
 *
 * @mixin CalendarEvent
 */
class CalendarEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'all_day' => $this->all_day,
            'color' => $this->color,
            // Null means no reminder. 0 is "when it starts", which is a different answer.
            'remind_minutes' => $this->remind_minutes,
            'reminded_at' => $this->reminded_at?->toIso8601String(),
            'room_channel_id' => $this->room_channel_id,
            // The room's name and kind, so the editor and the agenda can say "🔊 Standup"
            // without fetching the channel list to translate an id.
            'room' => $this->whenLoaded('roomChannel', fn () => $this->roomChannel === null ? null : [
                'id' => $this->roomChannel->id,
                'name' => $this->roomChannel->name,
                'type' => $this->roomChannel->type,
                // For the "copy meeting link" button: the client builds the path, so it needs
                // the server the room is in.
                'server_id' => $this->roomChannel->server_id,
            ]),
            'user' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at,
        ];
    }
}
