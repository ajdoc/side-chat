<?php

namespace App\Http\Requests\Auth;

use App\DTOs\Auth\RegisterUserData;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(RegisterUserData::validationRules(), [
            // `confirmed` is an HTTP form concern, not part of the domain payload.
            'password' => ['required', 'string', 'confirmed', 'min:8'],
        ]);
    }
}
