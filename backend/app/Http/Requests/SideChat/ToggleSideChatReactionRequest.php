<?php

namespace App\Http\Requests\SideChat;

use App\Http\Requests\MemberRequest;

/**
 * React to a post. Anyone in the channel may — reacting is reading out loud, and gating it
 * on the roster would mean a forum list nobody could vote on without joining every thread
 * in it. Posting *inside* the side chat still needs the roster; that's a different request.
 */
class ToggleSideChatReactionRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['emoji' => ['required', 'string', 'max:16']];
    }
}
