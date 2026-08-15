<?php

namespace App\Http\Requests\SideSpace;

use App\Http\Requests\MemberRequest;
use App\Models\SideSpaceMap;
use App\Support\SideSpace\MapPresets;
use Illuminate\Validation\Rule;

/**
 * Adding an interior — another room to this Side Space, behind a door.
 *
 * Any member, on the same reasoning as {@see UpdateSideSpaceMapRequest}: a Side Space is a room
 * a group builds, and adding a room to it is building. It is also the *safe* half of the pair —
 * a new interior is empty, unreachable until somebody hangs a door into it, and undone by
 * deleting it. Removing one is not, which is why {@see DestroySideSpaceMapRequest} is stricter.
 *
 * Only the name, the slug and a starting layout. The geometry arrives afterwards through the
 * ordinary map save, because an interior is a whole map and there is exactly one code path in
 * this app that knows how to validate one.
 */
class StoreSideSpaceMapRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],

            /*
             * How doors will point at this room, forever.
             *
             * Taken from the author rather than derived from the name, because it is the thing
             * portals store and a name is renameable — deriving it would silently re-slug the
             * room on a rename and break every door into it. Uniqueness is checked against this
             * channel only: two buildings may each have a `lobby`, and copying a channel copies
             * both the rooms and the links between them precisely because the links are by name.
             *
             * `main` is refused: that name belongs to the way in, which already exists.
             */
            'slug' => [
                'required',
                'string',
                'max:40',
                'regex:'.SideSpaceMap::SLUG_PATTERN,
                'not_in:'.SideSpaceMap::MAIN,
                Rule::unique('side_space_maps', 'slug')
                    ->where('channel_id', $this->route('channel')?->id),
            ],

            // What the new room starts as. A preset rather than a grid, so this endpoint never
            // has to validate geometry — see the class comment.
            'preset' => ['sometimes', 'nullable', 'string', Rule::in(MapPresets::keys())],

            /*
             * Which map the new room's way out should lead back to — the one hanging the door.
             *
             * The point of taking it is that the two halves of a doorway get built together. The
             * editor creates a room *from* a doorway it is drawing, so it knows the one thing
             * this endpoint otherwise couldn't: which map the door is being cut into. Without it
             * the way home can only lead to the building's front door.
             *
             * A *map* and not a tile. Where on that map you come out is decided when somebody
             * travels, by finding the doorway back — see the controller's wayHome() for why
             * storing a point was wrong.
             *
             * Optional, and it cannot fail the request: a slug naming no map falls back to the
             * way in rather than being refused.
             */
            'return_to' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex' => 'A room key may only use lowercase letters, numbers and dashes.',
            'slug.unique' => 'This Side Space already has a room with that key.',
            'slug.not_in' => '"main" is the way in — pick another key.',
        ];
    }
}
