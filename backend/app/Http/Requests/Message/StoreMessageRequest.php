<?php

namespace App\Http\Requests\Message;

use App\DTOs\Message\SendMessageData;
use App\Http\Requests\MemberRequest;
use App\Http\Requests\Message\UploadRules;
use Illuminate\Validation\Rule;

class StoreMessageRequest extends MemberRequest
{
    use UploadRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $channel = $this->route('channel');

        return array_merge(SendMessageData::validationRules(), $this->uploadRules(), [
            // A message needs text, files, or a GIF (or a mix).
            'body' => ['required_without_all:attachments,gif,uploads', 'nullable', 'string', 'max:2000'],
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
