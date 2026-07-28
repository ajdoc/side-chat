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
            // Scoped to this channel's own groups: a forum id from elsewhere would file the
            // post under a heading nobody reading this channel can see.
            'side_chat_forum_id' => [
                'nullable',
                Rule::exists('side_chat_forums', 'id')->where('channel_id', $channel->id),
            ],
        ]);
    }
}
