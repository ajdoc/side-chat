<?php

namespace App\DTOs\User;

use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class UpdatePreferencesData extends ValidatedDTO
{
    public ?string $theme_mode;
    public ?string $theme_color;

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
        return ['theme_mode' => null, 'theme_color' => null];
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [];
    }
}
