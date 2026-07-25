<?php

namespace App\Http\Requests\SideSpace;

use App\Http\Requests\MemberRequest;
use App\Services\Games\GameService;

/**
 * Proposing a game to the room. Membership is the gate — playing a game in a room is no more
 * privileged than talking in it. Whether there are enough people to start, and whether one's
 * already running, is the {@see GameService}'s to answer.
 */
class ProposeSpaceGameRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'max:40'],
            // Only for a challenge — who's being challenged. Ignored by room-wide games.
            'opponent' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
