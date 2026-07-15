<?php

namespace App\Http\Requests\Reaction;

use App\DTOs\Reaction\ToggleReactionData;
use App\Http\Requests\MemberRequest;

/**
 * Any member of the message's server may react — unlike editing, which is sender-only.
 */
class ToggleReactionRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ToggleReactionData::validationRules();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['emoji.regex' => 'Reactions must be an emoji.'];
    }
}
