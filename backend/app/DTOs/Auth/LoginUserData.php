<?php

namespace App\DTOs\Auth;

use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class LoginUserData extends ValidatedDTO
{
    public string $email;
    public string $password;

    /**
     * Single source of truth for validation — reused by the matching FormRequest.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
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
