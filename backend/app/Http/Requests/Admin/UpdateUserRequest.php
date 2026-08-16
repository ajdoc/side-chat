<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit an account's own details.
 *
 * Standing is not editable here — a role change is UpdateUserRoleRequest and a ban is
 * BanUserRequest, both with their own guards. Splitting them means a careless PATCH of a
 * display name can never quietly hand somebody the keys.
 *
 * The password is optional and, when given, replaces theirs outright. There's no
 * "current password" to confirm because the person doing this isn't the account's owner —
 * this is the reset for somebody locked out of an address they no longer control.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')->getKey()),
            ],
            'password' => ['sometimes', 'string', 'min:8'],
        ];
    }
}
