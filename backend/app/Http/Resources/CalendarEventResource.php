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
            'user' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at,
        ];
    }
}
