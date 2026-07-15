<?php

namespace App\Http\Requests\Server;

use App\DTOs\Server\UpdateServerData;
use App\Http\Requests\ServerOwnerRequest;

class UpdateServerRequest extends ServerOwnerRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return UpdateServerData::validationRules();
    }
}
