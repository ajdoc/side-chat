<?php

namespace App\Http\Requests\Thread;

use App\DTOs\Thread\CreateThreadData;
use App\Http\Requests\MemberRequest;
use Illuminate\Validation\Rule;

class StoreThreadRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $channel = $this->route('channel');

        return array_merge(CreateThreadData::validationRules(), [
            'message_id' => [
                'nullable',
                Rule::exists('messages', 'id')->where('channel_id', $channel->id),
            ],
        ]);
    }
}
