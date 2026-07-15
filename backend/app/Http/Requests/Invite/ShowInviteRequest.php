<?php

namespace App\Http\Requests\Invite;

use Illuminate\Foundation\Http\FormRequest;

/** Any authenticated user may look at an invite. */
class ShowInviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
