<?php

namespace App\Http\Requests\Channel;

use App\DTOs\Channel\CreateChannelData;
use App\Http\Requests\MemberRequest;

class StoreChannelRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return CreateChannelData::validationRules();
    }
}
