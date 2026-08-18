<?php

namespace App\Http\Resources;

use App\Models\MeetingJoin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a meeting's audit: who was admitted, when, and how.
 *
 * The IP and user agent are stored but **not shipped**. They exist so an operator looking at the
 * database can tell two strangers apart after an incident; putting them on a screen would make a
 * meeting's guest list a tracking record that everybody in the room can read.
 *
 * @mixin MeetingJoin
 */
class MeetingJoinResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => new UserResource($this->whenLoaded('user')),
            // 'link' — admitted by following it; 'member' — already had access to the room.
            'via' => $this->via,
            'external' => $this->external,
            'joined_at' => $this->created_at,
        ];
    }
}
