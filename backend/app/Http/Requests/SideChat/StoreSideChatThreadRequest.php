<?php

namespace App\Http\Requests\SideChat;

use App\DTOs\Thread\CreateThreadData;
use App\Models\SideChat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Starting a thread inside a side chat is a taking-part power — you must be on the roster,
 * the same gate as posting a message here. A thread that branches off a message must branch
 * off one of *this* side chat's messages.
 */
class StoreSideChatThreadRequest extends FormRequest
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

        return array_merge(CreateThreadData::validationRules(), [
            'message_id' => [
                'nullable',
                Rule::exists('messages', 'id')->where('side_chat_id', $sideChat->id),
            ],
        ]);
    }
}
