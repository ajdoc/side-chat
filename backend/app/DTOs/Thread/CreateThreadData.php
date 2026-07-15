<?php

namespace App\DTOs\Thread;

use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class CreateThreadData extends ValidatedDTO
{
    public string $name;
    public ?int $message_id;

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
        return ['message_id' => null];
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [];
    }
}
