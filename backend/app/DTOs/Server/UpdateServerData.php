<?php

namespace App\DTOs\Server;

use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class UpdateServerData extends ValidatedDTO
{
    public string $name;

    /**
     * Single source of truth for validation — reused by the matching FormRequest.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
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
