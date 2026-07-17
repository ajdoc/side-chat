<?php

namespace App\Http\Requests\SideChat;

use App\DTOs\SideChat\CreateSideChatData;
use App\Http\Requests\MemberRequest;
use Illuminate\Validation\Rule;

class StoreSideChatRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $channel = $this->route('channel');

        return array_merge(CreateSideChatData::validationRules(), [
            'message_id' => [
                'nullable',
                Rule::exists('messages', 'id')->where('channel_id', $channel->id),
            ],
        ]);
    }
}
