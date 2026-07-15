<?php

namespace App\Http\Requests\Server;

use App\DTOs\Server\CreateServerData;
use Illuminate\Foundation\Http\FormRequest;

class StoreServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return CreateServerData::validationRules();
    }
}
