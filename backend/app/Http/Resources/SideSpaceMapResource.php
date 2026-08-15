<?php

namespace App\Http\Resources;

use App\Models\SideSpaceExhibit;
use App\Models\SideSpaceMap;
use App\Support\SideSpace\Doors;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Side Space's map, whole. There is no partial form of this: the browser has to draw every
 * tile and answer "is this solid" for every step, so it gets the entire grid or nothing.
 *
 * @mixin SideSpaceMap
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
            // Which room of the building this is. `main` is the way in; see the migration.
            'slug' => $this->slug,
            'name' => $this->name,
            /*
             * The building's other rooms — key and name only, never their grids.
             *
             * Rides with every read of a map because both things that need it need it *while you
             * are standing somewhere*: the editor's room switcher, and the destination picker on
             * a doorway. Sending the names with the map costs a few dozen bytes and saves a
             * second endpoint that would be called immediately after this one every time.
             *
             * Names, not geometry. An interior is a full map and there is no version of "a bit
             * of one" worth sending — walking through the door fetches it.
             */
            'siblings' => $this->whenLoaded('channel', fn () => $this->channel->spaceMaps
                ->map(fn (SideSpaceMap $map) => ['slug' => $map->slug, 'name' => $map->name])
                ->values()
                ->all()),
            'width' => $this->width,
            'height' => $this->height,
            'tiles' => $this->tiles,
            'zones' => $this->zones,
            // Which way to draw the grid — flat, or the isometric view. See the migration that
            // added it, and lib/spaceProjection.ts for what each one means on screen.
            'projection' => $this->projection ?? 'flat',
            // Keys and rectangles only. The browser has the same catalogue and resolves a key to
            // a path, so there is nothing to send that it doesn't already know.
            'backdrops' => $this->backdrops ?? [],
            /*
             * Doorways, with where each one goes.
             *
             * Sent to everybody, like zones and locked doors are, because the browser has to
             * decide *as somebody walks* whether they have just stepped into one — and it has to
             * decide it for every person in the room, not only the one at this keyboard. Whether
             * a particular person may actually pass through a door into another room is checked
             * again when they use it; this is the geometry, not the permission.
             */
            'portals' => $this->portals ?? [],
            /*
             * Where a shared screen is painted in the room.
             *
             * Geometry, like the doorways above, and sent to everybody for the same reason: every
             * browser draws the whole room including the parts of it nobody is standing in. What
             * *appears* on a screen never comes through here — that is the call's business, and
             * each browser paints whatever share it is already receiving.
             */
            'screens' => $this->screens ?? [],
            // The frames. Rectangles only — what is hanging in one arrives below, from its own
            // table, because a member editing this document may move a frame but not fill it.
            'exhibits' => $this->exhibits ?? [],
            /*
             * What is actually hanging, keyed by frame.
             *
             * Labels and a URL, never the bytes. The image is fetched when somebody opens a
             * frame and not before — a gallery of eighty paintings must not be eighty downloads
             * for anybody who walks in, and most of them will never be looked at.
             *
             * The URL is signed and expiring (see SideSpaceExhibit::url), which is what lets an
             * <img> in a private channel's museum work with no auth header and still not be
             * readable by anybody who guesses the path.
             */
            'exhibit_pieces' => $this->whenLoaded('exhibitPieces', fn () => $this->exhibitPieces
                ->map(fn (SideSpaceExhibit $piece) => [
                    'exhibit_id' => $piece->exhibit_id,
                    'title' => $piece->title,
                    'artist' => $piece->artist,
                    'caption' => $piece->caption,
                    'url' => $piece->url(),
                ])
                ->values()
                ->all()),
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
