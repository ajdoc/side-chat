<?php

namespace App\Http\Requests\Thread;

use App\DTOs\Message\SendMessageData;
use App\Http\Requests\MemberRequest;
use Illuminate\Validation\Rule;

class StoreThreadMessageRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $thread = $this->route('thread');

        return array_merge(SendMessageData::validationRules(), [
            'body' => ['required_without:attachments', 'nullable', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:20480'], // 20 MB each
            // A reply must target a message inside this same thread.
            'reply_to_id' => [
                'nullable',
                Rule::exists('messages', 'id')->where('thread_id', $thread->id),
            ],
        ]);
    }
}
