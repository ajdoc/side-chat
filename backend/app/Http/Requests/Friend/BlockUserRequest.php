<?php

namespace App\Http\Requests\Friend;

use Illuminate\Foundation\Http\FormRequest;

/** Block someone. Anyone may block anyone; no relationship is required first. */
class BlockUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
