<?php

namespace App\Http\Requests\Message;

use App\DTOs\Message\UpdateMessageData;
use App\Http\Requests\MessageOwnerRequest;
use Illuminate\Validation\Rule;

class UpdateMessageRequest extends MessageOwnerRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(UpdateMessageData::validationRules(), [
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:20480'], // 20 MB each
            // Removals must belong to this message.
            'remove_attachment_ids.*' => [
                'integer',
                Rule::exists('attachments', 'id')->where('message_id', $this->route('message')->id),
            ],
        ]);
    }
}
