<?php

namespace App\Http\Requests\SideSpace;

use App\Http\Requests\MemberRequest;
use App\Services\Games\GameService;

/**
 * Voting a proposed game in or not. The vote is a plain yes/no; the tally, and the moment a
 * majority tips it into starting, are the {@see GameService}'s.
 */
class VoteSpaceGameRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'vote' => ['required', 'boolean'],
        ];
    }
}
