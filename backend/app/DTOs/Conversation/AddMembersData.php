<?php

namespace App\DTOs\Conversation;

use App\Models\Conversation;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class AddMembersData extends ValidatedDTO
{
    /** @var array<int, int> */
    public array $user_ids;

    /**
     * Single source of truth for validation — reused by the matching FormRequest.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:'.Conversation::MAX_GROUP_MEMBERS],
            'user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
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
