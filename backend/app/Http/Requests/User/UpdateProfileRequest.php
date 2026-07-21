<?php

namespace App\Http\Requests\User;

use App\DTOs\User\UpdateProfileData;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return UpdateProfileData::validationRules();
    }
}
