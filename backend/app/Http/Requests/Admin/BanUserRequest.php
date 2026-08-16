<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Block an account, with the message they'll be shown.
 *
 * The reason is required and has a floor of a few characters, because it is not an internal
 * note — it's the entire text the person reads at the login screen, and "no" is not an
 * explanation anybody can act on. Capped at 500 so it still fits in the error slot on the
 * form rather than becoming a page of its own.
 */
class BanUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Give a reason — the blocked person is shown this when they try to sign in.',
        ];
    }
}
