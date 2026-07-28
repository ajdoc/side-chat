<?php

namespace App\DTOs\SideChat;

use App\Models\SideChat;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class CreateSideChatData extends ValidatedDTO
{
    public string $name;
    public ?int $message_id;

    /**
     * The forum layer's labels, set at creation so a post can be filed the moment it's
     * opened rather than tagged as an afterthought. Cleaned by UpdateSideChatAction's
     * normaliser — see there for why they're lowercased.
     *
     * @var array<int, string>|null
     */
    public ?array $tags;

    /**
     * Single source of truth for validation — reused by the matching FormRequest.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'message_id' => ['nullable', 'integer'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:'.SideChat::MAX_TAGS],
            'tags.*' => ['string', 'max:'.SideChat::MAX_TAG_LENGTH],
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
        return ['message_id' => null, 'tags' => null];
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [];
    }
}
