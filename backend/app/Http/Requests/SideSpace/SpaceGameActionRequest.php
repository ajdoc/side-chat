<?php

namespace App\Http\Requests\SideSpace;

use App\Http\Requests\MemberRequest;

/**
 * A move in a running game — a task done, a kill, a vote. The framework knows only that it's
 * "an action with a payload"; what a given action *means* is the handler's, and what it's allowed
 * to do is checked there against the game's own state.
 */
class SpaceGameActionRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'max:40'],
            'payload' => ['sometimes', 'array'],
        ];
    }
}
