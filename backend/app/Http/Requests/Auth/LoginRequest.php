<?php

namespace App\Http\Requests\Auth;

use App\DTOs\Auth\LoginUserData;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return LoginUserData::validationRules();
    }
}
