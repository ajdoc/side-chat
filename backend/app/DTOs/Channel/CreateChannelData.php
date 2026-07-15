<?php

namespace App\DTOs\Channel;

use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class CreateChannelData extends ValidatedDTO
{
    public string $name;
    public string $type;

    /**
     * Single source of truth for validation — reused by the matching FormRequest.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', 'in:text,voice'],
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
