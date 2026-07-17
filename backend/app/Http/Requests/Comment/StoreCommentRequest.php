<?php

namespace App\Http\Requests\Comment;

use App\DTOs\Comment\AddCommentData;
use App\Http\Requests\MemberRequest;

/**
 * Any member of the message's container may comment — like reacting, unlike editing.
 */
class StoreCommentRequest extends MemberRequest
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
