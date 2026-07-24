<?php

namespace App\DTOs\Channel;

use App\Models\Channel;
use App\Support\SideSpace\MapPresets;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class CreateChannelData extends ValidatedDTO
{
    public string $name;
    public string $type;

    /**
     * Which room a Side Space starts as. Null for every other channel type — and required for
     * a Side Space, because a map has to exist before anybody can walk into it and there is no
     * sensible default room to invent on their behalf.
     */
    public ?string $preset;

    /**
     * Single source of truth for validation — reused by the matching FormRequest.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', 'in:'.implode(',', Channel::TYPES)],
            'preset' => ['nullable', 'required_if:type,space', 'string', 'in:'.implode(',', MapPresets::keys())],
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
        // Explicit, so `preset` is always defined rather than unset on the three channel types
        // that have no map.
        return ['preset' => null];
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [];
    }
}
