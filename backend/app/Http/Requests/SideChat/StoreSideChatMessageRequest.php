<?php

namespace App\Http\Requests\SideChat;

use App\DTOs\Message\SendMessageData;
use App\Models\SideChat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Posting in a side chat needs more than channel membership — you have to have joined its
 * roster. That is the line between reading one and taking part in it.
 */
class StoreSideChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $sideChat = $this->route('sideChat');
        $user = $this->user();

        return $sideChat instanceof SideChat
            && $user !== null
            && $sideChat->hasParticipant($user);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $sideChat = $this->route('sideChat');

        return array_merge(SendMessageData::validationRules(), [
            'body' => ['required_without:attachments', 'nullable', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:20480'], // 20 MB each
            // A reply must target a message inside this same side chat.
            'reply_to_id' => [
                'nullable',
                Rule::exists('messages', 'id')->where('side_chat_id', $sideChat->id),
            ],
        ]);
    }
}
