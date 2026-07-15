<?php

namespace App\DTOs\Conversation;

use App\Models\Conversation;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class CreateGroupData extends ValidatedDTO
{
    public string $name;

    /** @var array<int, int> */
    public array $user_ids;

    /**
     * Single source of truth for validation — reused by the matching FormRequest.
     *
     * The cap is the mesh's, not a product decision: everyone in a call sends their audio
     * separately to everyone else, so a group big enough to be a broadcast is a group whose
     * call cannot work. Better to refuse the 26th person here than to let the call collapse
     * for the other 25. (config/webrtc.php caps the *call* lower still.)
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'user_ids' => ['required', 'array', 'min:1', 'max:'.(Conversation::MAX_GROUP_MEMBERS - 1)],
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
