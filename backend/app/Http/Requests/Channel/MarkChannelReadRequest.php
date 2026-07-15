<?php

namespace App\Http\Requests\Channel;

use App\Http\Requests\MemberRequest;
use Illuminate\Validation\Rule;

class MarkChannelReadRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $channel = $this->route('channel');

        return [
            // Omit it to mean "I've read everything in here".
            'message_id' => [
                'nullable',
                'integer',
                Rule::exists('messages', 'id')->where('channel_id', $channel->id),
            ],
        ];
    }
}
