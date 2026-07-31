<?php

namespace App\Http\Resources;

use App\Support\SideSpace\Doors;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Side Space's map, whole. There is no partial form of this: the browser has to draw every
 * tile and answer "is this solid" for every step, so it gets the entire grid or nothing.
 *
 * @mixin \App\Models\SideSpaceMap
 */
class SideSpaceMapResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'name' => $this->name,
            'width' => $this->width,
            'height' => $this->height,
            'tiles' => $this->tiles,
            'zones' => $this->zones,
            // Furniture. Positions and kinds only — the browser has the same catalogue and
            // looks the rest up, so there is nothing to send that it doesn't already know.
            'objects' => $this->objects ?? [],
            'spawn' => $this->spawn,
            /*
             * Who is in charge of which room, and which doors are shut to whom.
             *
             * Travels with the map because it is *drawing* information as much as it is
             * permission: a browser has to decide, sixty times a second, whether the door in
             * front of somebody swings open — and not only for the person holding the keyboard.
             * A door that opened on my screen because I may pass, while staying shut on yours as
             * you walked through it, is worse than a door that never opens.
             *
             * So the key-holder list is shared with everybody in the room rather than filtered
             * to the reader. It is not a secret: a locked door is visibly locked, and who may go
             * in is the sort of thing a room tells you by watching it for a minute. What *is*
             * scoped is the management view — see the locks endpoint, where requirement 5 lives.
             */
            // One row per *person* per room, so a room with three owners appears three times.
            // The client groups by `zone_id`; sending it pre-grouped would mean a second shape
            // for the same fact, and the grouping is a one-liner where it's used.
            'rooms' => $this->whenLoaded('rooms', fn () => $this->rooms
                ->map(fn ($room) => [
                    'zone_id' => $room->zone_id,
                    'owner_id' => $room->owner_id,
                    'owner' => $room->owner?->name,
                ])
                ->values()
                ->all()),
            'locks' => $this->whenLoaded('locks', fn () => $this->locks
                ->map(fn ($lock) => [
                    'object_id' => $lock->object_id,
                    'zone_id' => $lock->zone_id,
                    // Resolved, not stored — see Doors::granted. The standing keys only: a pass
                    // bought with the password is below, with its deadline attached.
                    'allowed' => Doors::granted($this->resource, $lock),
                    /*
                     * Who is through on a password, and when each of them stops being.
                     *
                     * Sent as a *deadline* rather than as membership of a list, because the pass
                     * lapses without anybody doing anything and there is no event to broadcast
                     * when it does. Every browser is already deciding this door's state sixty
                     * times a second from the map it holds; give it the moment the pass ends and
                     * it closes the door on time, in step with every other screen in the room,
                     * with no second round trip and nothing to miss.
                     *
                     * Milliseconds, to be compared against Date.now() unchanged.
                     */
                    'passes' => collect($lock->activePasses())
                        ->map(fn (int $until, int $id) => ['id' => $id, 'until' => $until * 1000])
                        ->values()
                        ->all(),
                    /*
                     * Whether the door will open for somebody who knows the words.
                     *
                     * The fact, never the phrase — the hash is `hidden` on the model and has no
                     * business in a payload every browser in the room receives. It travels
                     * because the door has to *say* so: "locked, and there's a password" is an
                     * invitation to try, while a padlock with no explanation is a dead end, and
                     * the two must not look the same.
                     */
                    'has_password' => $lock->hasPassword(),
                ])
                ->values()
                ->all()),
            'updated_by' => $this->whenLoaded('editor', fn () => $this->editor?->name),
            'updated_at' => $this->updated_at,
        ];
    }
}
