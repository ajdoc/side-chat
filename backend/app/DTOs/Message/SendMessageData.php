<?php

namespace App\DTOs\Message;

use WendellAdriel\ValidatedDTO\Casting\IntegerCast;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class SendMessageData extends ValidatedDTO
{
    public ?string $body;
    public ?int $reply_to_id;

    /**
     * Single source of truth for validation — reused by the matching FormRequest.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:2000'],
            'reply_to_id' => ['nullable', 'integer'],
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
        return ['body' => null, 'reply_to_id' => null];
    }

    /**
     * A message with attachments must be sent as multipart/form-data, where every
     * field arrives as a string — so `reply_to_id` shows up as "12", not 12.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['reply_to_id' => new IntegerCast()];
    }
}
