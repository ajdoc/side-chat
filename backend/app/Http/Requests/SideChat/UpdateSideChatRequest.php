<?php

namespace App\Http\Requests\SideChat;

use App\Models\SideChat;
use Illuminate\Validation\Rule;

class UpdateSideChatRequest extends ManageSideChatRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var SideChat $sideChat */
        $sideChat = $this->route('sideChat');

        return [
            /*
             * Moving a post between groups. Null means "back to Uncategorised", which is
             * why the rule is `nullable` rather than `exists` alone — un-filing a post has
             * to be as expressible as filing it.
             *
             * Scoped to the post's own channel: a group belongs to a channel, so filing a
             * post under one from elsewhere would be filing it out of sight.
             */
            'side_chat_forum_id' => [
                'sometimes',
                'nullable',
                Rule::exists('side_chat_forums', 'id')->where('channel_id', $sideChat->channel_id),
            ],
            // Both optional: the dialog may be editing only the title, or only the tags,
            // and absent has to mean "leave it" rather than "clear it".
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'tags' => ['sometimes', 'array', 'max:'.SideChat::MAX_TAGS],
            'tags.*' => ['string', 'max:'.SideChat::MAX_TAG_LENGTH],
        ];
    }
}
