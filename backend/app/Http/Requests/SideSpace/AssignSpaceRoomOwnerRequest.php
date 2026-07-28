<?php

namespace App\Http\Requests\SideSpace;

use App\Http\Requests\ServerStaffRequest;

/**
 * Putting somebody in charge of a room. The server's owner, and nobody else.
 *
 * This is the one gate in the Side Space that stayed shut when the rest opened. Building the
 * room is collaborative — any member may lay a floor or move a couch — but *appointing* somebody
 * is not a building decision, it's a delegation, and a delegation anybody could make is not one.
 * It's also the root of every other permission here: a room owner can lock doors, so a member
 * who could appoint themselves could lock the whole map.
 *
 * An empty list un-assigns the room, which is how a room goes back to being nobody's.
 */
class AssignSpaceRoomOwnerRequest extends ServerStaffRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // The whole set, not one name. A room can be several people's, so the payload
            // replaces the list rather than adding to it — which makes removing somebody the
            // same call as adding them, and leaves no way to end up half-applied.
            'owner_ids' => ['present', 'array', 'max:50'],
            'owner_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
