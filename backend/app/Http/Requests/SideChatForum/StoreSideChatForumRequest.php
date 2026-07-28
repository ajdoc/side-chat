<?php

namespace App\Http\Requests\SideChatForum;

use App\Models\SideChatForum;
use Illuminate\Validation\Rule;

class StoreSideChatForumRequest extends ManageSideChatForumRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:'.SideChatForum::MAX_NAME_LENGTH,
                // Matches the table's unique key, so the collision comes back as a field
                // error on the dialog rather than a 500 from the database.
                Rule::unique('side_chat_forums', 'name')->where('channel_id', $this->route('channel')->id),
            ],
        ];
    }
}
