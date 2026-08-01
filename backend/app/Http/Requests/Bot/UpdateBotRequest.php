<?php

namespace App\Http\Requests\Bot;

use App\DTOs\Bot\UpdateBotData;
use App\Http\Requests\ServerOwnerRequest;

class UpdateBotRequest extends ServerOwnerRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return UpdateBotData::validationRules();
    }
}
