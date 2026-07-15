<?php

namespace App\Http\Requests\Conversation;

use App\DTOs\Conversation\UpdateConversationData;
use App\Http\Requests\MemberRequest;
use App\Models\Conversation;
use Illuminate\Validation\Validator;

/**
 * Renaming a group.
 *
 * The owner's, unlike everything else here. Not because renaming is dangerous, but because
 * a name that anyone can change is a name that means nothing — and unlike a message, you
 * can't tell who changed it or what it used to be.
 */
class UpdateConversationRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return UpdateConversationData::validationRules();
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $conversation = $this->route('conversation');

                if ($conversation instanceof Conversation && $conversation->owner_id !== $this->user()?->id) {
                    $validator->errors()->add('conversation', 'Only the person who made this group can rename it.');
                }
            },
        ];
    }
}
