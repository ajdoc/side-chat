<?php

namespace App\DTOs\Reaction;

use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class ToggleReactionData extends ValidatedDTO
{
    public string $emoji;

    /**
     * Emoji only — no arbitrary text. Matches a pictographic grapheme and the pieces
     * that can legitimately make one up: variation selectors, ZWJ (for sequences like
     * 👩‍💻), skin-tone modifiers, and the digit/#/* bases of keycaps (1️⃣).
     */
    public const EMOJI_PATTERN = '/^(?:\p{Extended_Pictographic}|[\x{1F3FB}-\x{1F3FF}]|[\x{200D}\x{FE0F}\x{20E3}]|[0-9#*])+$/u';

    /**
     * Single source of truth for validation — reused by the matching FormRequest.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'emoji' => ['required', 'string', 'max:32', 'regex:'.self::EMOJI_PATTERN],
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
        return [];
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [];
    }
}
