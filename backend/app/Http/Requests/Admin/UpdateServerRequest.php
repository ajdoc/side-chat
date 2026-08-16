<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit a server from the panel.
 *
 * Ownership is transferable here and nowhere else: an owner can't hand their server over
 * from inside the app, so an abandoned server currently has no route back to a live owner
 * that doesn't go through an operator.
 */
class UpdateServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            // The new owner must be a real, non-bot account. Membership isn't required —
            // handing a dead server to an operator is a legitimate rescue.
            // The closure form, not ->where('is_bot', false): that binds the false as an
            // empty string, which Postgres refuses outright on a boolean column.
            'owner_id' => [
                'sometimes', 'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('is_bot', false)),
            ],
            'discussion_creation' => ['sometimes', 'string', Rule::in(['everyone', 'staff'])],
            'sfu_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
