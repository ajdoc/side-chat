<?php

namespace App\Http\Requests\SideSpace;

use App\Http\Requests\MemberRequest;

/**
 * Remembering where somebody was standing. Membership is the whole gate — you can only write
 * your own row, and the endpoint finds it by the authenticated user rather than taking an id.
 *
 * Note what *isn't* validated: whether the tile is walkable. This records where a client says
 * it was, on a long throttle, so it can be put back there on reload — and by the time it's read
 * the map may have been repainted underneath it. {@see \App\Models\SideSpaceMap::spawnPoint()}
 * is where a position that has stopped making sense gets corrected, which is the right place
 * for it: at the moment of use, against the map as it is then.
 */
class UpdateSpacePositionRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'x' => ['required', 'integer', 'min:0', 'max:1000'],
            'y' => ['required', 'integer', 'min:0', 'max:1000'],
            'facing' => ['nullable', 'string', 'in:up,down,left,right'],
        ];
    }
}
