<?php

namespace App\Http\Requests\Thread;

class UpdateThreadRequest extends ThreadAuthorRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255']];
    }
}
