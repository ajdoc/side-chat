<?php

namespace App\DTOs\Server;

use App\Models\Server;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class UpdateServerData extends ValidatedDTO
{
    public string $name;

    /** Who may add a discussion to a channel here — `everyone` (the default) or `staff`. */
    public ?string $discussion_creation;

    /**
     * Whether calls here may go through an SFU. Null means the request didn't mention it and
     * the current setting stands — see the defaults below, and UpdateServerAction.
     */
    public ?bool $sfu_enabled;

    /**
     * Single source of truth for validation — reused by the matching FormRequest.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'discussion_creation' => ['nullable', 'string', 'in:'.implode(',', Server::DISCUSSION_CREATION)],
            'sfu_enabled' => ['nullable', 'boolean'],
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
        // Explicit, so the field is always defined — an update that doesn't mention the policy
        // must leave it alone rather than reset it (see UpdateServerAction).
        return ['discussion_creation' => null, 'sfu_enabled' => null];
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [];
    }
}
