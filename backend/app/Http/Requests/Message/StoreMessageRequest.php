<?php

namespace App\Http\Requests\Message;

use App\DTOs\Message\SendMessageData;
use App\Http\Requests\MemberRequest;
use Illuminate\Validation\Rule;

class StoreMessageRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $channel = $this->route('channel');

        return array_merge(SendMessageData::validationRules(), [
            // A message needs text, files, or both.
            'body' => ['required_without:attachments', 'nullable', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:20480'], // 20 MB each
            // A reply must target a main-timeline message in this same channel.
            'reply_to_id' => [
                'nullable',
                Rule::exists('messages', 'id')
                    ->where('channel_id', $channel->id)
                    ->whereNull('thread_id'),
            ],
        ]);
    }
}
