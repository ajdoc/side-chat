<?php

namespace App\DTOs\Comment;

use App\DTOs\Reaction\ToggleReactionData;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class AddCommentData extends ValidatedDTO
{
    public string $body;
    public ?string $emoji;

    /**
     * Single source of truth for validation — reused by the matching FormRequest.
     *
     * The body is short on purpose (80–120 characters feels about right for feedback).
     * The optional emoji reuses the reaction grapheme rule: at most one pictograph, no
     * arbitrary text sneaking in through the emoji slot.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'body' => ['required', 'string', 'max:120'],
            'emoji' => ['nullable', 'string', 'max:32', 'regex:'.ToggleReactionData::EMOJI_PATTERN],
        ];
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return self::validationRules();
    }

    /** @return array<string, mixed> */
    protected function defaults(): array
    {
        return ['emoji' => null];
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [];
    }
}
