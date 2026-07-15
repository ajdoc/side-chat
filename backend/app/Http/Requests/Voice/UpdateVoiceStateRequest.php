<?php

namespace App\Http\Requests\Voice;

use App\DTOs\Voice\UpdateVoiceStateData;

class UpdateVoiceStateRequest extends VoiceChannelRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return UpdateVoiceStateData::validationRules();
    }
}
