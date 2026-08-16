<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Grant or revoke a site role.
 *
 * `null` is a valid value and means "no role" — that's the demotion, so it has to be
 * `present` rather than `required`, which would reject exactly the case we need.
 */
class UpdateUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role' => ['present', 'nullable', Rule::in(User::ROLES)],
        ];
    }
}
