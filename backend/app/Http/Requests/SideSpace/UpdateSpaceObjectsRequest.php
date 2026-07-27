<?php

namespace App\Http\Requests\SideSpace;

use App\Http\Requests\MemberRequest;
use App\Support\SideSpace\Decorations;
use Illuminate\Validation\Rule;

/**
 * Decorating a room — the furniture layer alone, open to any member.
 *
 * This is the deliberate split that lets decorating be shared without letting the room be
 * vandalised. Rebuilding the *geometry* stays owner-only ({@see UpdateSideSpaceMapRequest}),
 * because a wall painted through somebody is an edit no member should be able to make. But
 * *furniture* changes nothing anybody collides against in a way that can trap them — the worst a
 * misplaced couch does is need moving — so anyone in the room may rearrange it.
 *
 * The payload is furniture and nothing else: the tiles it has to fit against are the map's own,
 * read on the server, never sent. A member has no way to move a wall, so there is nothing to
 * check a member's walls against. The structural check therefore runs in the controller, where
 * the stored map is in hand.
 */
class UpdateSpaceObjectsRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'objects' => ['present', 'array', 'max:'.Decorations::MAX_PER_MAP],
            'objects.*.id' => ['required', 'string', 'max:40'],
            'objects.*.kind' => ['required', 'string', Rule::in(Decorations::keys())],
            'objects.*.x' => ['required', 'integer', 'min:0'],
            'objects.*.y' => ['required', 'integer', 'min:0'],
            // Optional, and absent means the front view every piece had before pieces could be
            // turned — so an old client's payload is still a valid room.
            'objects.*.facing' => ['sometimes', 'string', Rule::in(Decorations::FACINGS)],
        ];
    }
}
