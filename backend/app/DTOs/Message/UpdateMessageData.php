<?php

namespace App\DTOs\Message;

use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class UpdateMessageData extends ValidatedDTO
{
    public ?string $body;
    public ?array $remove_attachment_ids;

    /**
     * Single source of truth for validation — reused by the matching FormRequest.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:2000'],
            'remove_attachment_ids' => ['nullable', 'array'],
            'remove_attachment_ids.*' => ['integer'],
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
        return ['body' => null, 'remove_attachment_ids' => []];
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [];
    }
}
