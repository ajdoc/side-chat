<?php

namespace App\Http\Requests\User;

use App\DTOs\User\UpdatePreferencesData;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return UpdatePreferencesData::validationRules();
    }
}
