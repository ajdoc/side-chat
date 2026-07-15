<?php

namespace App\Http\Requests\Channel;

use App\DTOs\Channel\UpdateChannelData;
use App\Http\Requests\ServerOwnerRequest;

class UpdateChannelRequest extends ServerOwnerRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return UpdateChannelData::validationRules();
    }
}
