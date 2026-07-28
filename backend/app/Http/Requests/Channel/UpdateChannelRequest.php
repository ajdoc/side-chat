<?php

namespace App\Http\Requests\Channel;

use App\DTOs\Channel\UpdateChannelData;
use App\Http\Requests\ServerStaffRequest;

class UpdateChannelRequest extends ServerStaffRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return UpdateChannelData::validationRules();
    }
}
