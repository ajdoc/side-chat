<?php

namespace App\Http\Requests\Server;

use App\DTOs\Server\UpdateServerData;
use App\Http\Requests\ServerStaffRequest;

class UpdateServerRequest extends ServerStaffRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return UpdateServerData::validationRules();
    }
}
