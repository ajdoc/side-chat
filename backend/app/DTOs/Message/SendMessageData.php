<?php

namespace App\DTOs\Message;

use WendellAdriel\ValidatedDTO\Casting\BooleanCast;
use WendellAdriel\ValidatedDTO\Casting\IntegerCast;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class SendMessageData extends ValidatedDTO
{
    public ?string $body;
    public ?int $reply_to_id;

    /**
     * Addressed at the side chat *post* rather than at another message. Only meaningful on
     * the side-chat send path; nothing else sets it, and the request there is the only one
     * that validates it. See the migration for why it isn't folded into `reply_to_id`.
     *
     * Nullable, not `bool`: the cast runs over the raw payload, and an absent (or
     * explicitly null) field casts to null before the default can be applied — assigning
     * that to a `bool` property is a TypeError. Readers coalesce; absent means "no".
     */
    public ?bool $replies_to_post;

    /**
     * A GIF picked from the provider (Giphy): `url` is the media URL we store as a remote
     * attachment, the rest is metadata. Null for an ordinary message. See SendMessageAction.
     *
     * @var array{url: string, preview_url?: string|null, title?: string|null, width?: int|null, height?: int|null}|null
     */
    public ?array $gif;

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
            'replies_to_post' => ['nullable', 'boolean'],
            'gif' => ['nullable', 'array'],
            'gif.url' => ['required_with:gif', 'string', 'url', 'max:2048'],
            'gif.preview_url' => ['nullable', 'string', 'url', 'max:2048'],
            'gif.title' => ['nullable', 'string', 'max:255'],
            'gif.width' => ['nullable', 'integer'],
            'gif.height' => ['nullable', 'integer'],
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
        return ['body' => null, 'reply_to_id' => null, 'replies_to_post' => false, 'gif' => null];
    }

    /**
     * A message with attachments must be sent as multipart/form-data, where every
     * field arrives as a string — so `reply_to_id` shows up as "12", not 12.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        // Same reason for the boolean: over multipart it arrives as the string "1"/"0".
        return ['reply_to_id' => new IntegerCast(), 'replies_to_post' => new BooleanCast()];
    }
}
