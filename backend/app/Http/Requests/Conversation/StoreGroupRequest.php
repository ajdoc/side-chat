<?php

namespace App\Http\Requests\Conversation;

use App\DTOs\Conversation\CreateGroupData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreGroupRequest extends FormRequest
{
    use ChecksReachableMembers;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return CreateGroupData::validationRules();
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->assertReachable($validator),
        ];
    }
}
