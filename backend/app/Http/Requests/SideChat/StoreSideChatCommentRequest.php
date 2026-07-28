<?php

namespace App\Http\Requests\SideChat;

use App\DTOs\Comment\AddCommentData;
use App\Http\Requests\MemberRequest;

/**
 * Comment on a post. Anyone in the channel may, exactly as with reacting to one and for
 * the same reason: a forum list you'd have to join a post to speak about isn't a list.
 * Posting *inside* the side chat still needs the roster — a different request.
 */
class StoreSideChatCommentRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return AddCommentData::validationRules();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['emoji.regex' => 'The emoji must be a single emoji.'];
    }
}
