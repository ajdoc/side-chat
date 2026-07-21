<?php

namespace App\DTOs\User;

use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class UpdateProfileData extends ValidatedDTO
{
    public ?string $name;

    /**
     * Single source of truth for validation — reused by the matching FormRequest.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            // The display name everyone else sees you by. Not unique — two people may
            // genuinely be called the same thing, and the id is what identifies them.
            'name' => ['sometimes', 'string', 'min:1', 'max:50'],
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
        return ['name' => null];
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [];
    }
}
