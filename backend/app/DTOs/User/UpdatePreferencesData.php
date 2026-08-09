<?php

namespace App\DTOs\User;

use App\Support\Notifications\NotifyLevel;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class UpdatePreferencesData extends ValidatedDTO
{
    public ?string $theme_mode;

    public ?string $theme_color;

    /** Account-wide defaults, overridden per place on `channel_reads`. */
    public ?string $notify_channel_default;

    public ?string $notify_dm_default;

    /** The master switch for push specifically — see the users migration. */
    public ?bool $push_enabled;

    /**
     * Single source of truth for validation — reused by the matching FormRequest.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'theme_mode' => ['sometimes', 'string', 'in:light,dark,system'],
            // Each accent drives the frontend's whole palette, not just its buttons —
            // the matching registry lives in frontend/app/assets/css/tailwind.css.
            'theme_color' => ['sometimes', 'string', 'in:slate,blue,violet,rose,red,amber,green,teal'],
            // Note these take no null: an account default is the bottom of the resolution
            // chain and has nothing to inherit from, so "no opinion" isn't available here
            // the way it is on an individual channel.
            'notify_channel_default' => ['sometimes', 'string', 'in:'.implode(',', NotifyLevel::values())],
            'notify_dm_default' => ['sometimes', 'string', 'in:'.implode(',', NotifyLevel::values())],
            'push_enabled' => ['sometimes', 'boolean'],
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
        return [
            'theme_mode' => null,
            'theme_color' => null,
            'notify_channel_default' => null,
            'notify_dm_default' => null,
            'push_enabled' => null,
        ];
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [];
    }
}
