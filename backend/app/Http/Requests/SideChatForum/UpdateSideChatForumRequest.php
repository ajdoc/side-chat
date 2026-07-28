<?php

namespace App\Http\Requests\SideChatForum;

use App\Models\SideChatForum;
use Illuminate\Validation\Rule;

class UpdateSideChatForumRequest extends ManageSideChatForumRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var SideChatForum $forum */
        $forum = $this->route('forum');

        return [
            // Both optional: the panel renames from a dialog and reorders by dragging, and
            // neither edit should have to restate the other.
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:'.SideChatForum::MAX_NAME_LENGTH,
                Rule::unique('side_chat_forums', 'name')
                    ->where('channel_id', $forum->channel_id)
                    ->ignore($forum->id),
            ],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
