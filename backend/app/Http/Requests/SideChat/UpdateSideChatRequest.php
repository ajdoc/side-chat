<?php

namespace App\Http\Requests\SideChat;

use App\Models\SideChat;

class UpdateSideChatRequest extends ManageSideChatRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Both optional: the dialog may be editing only the title, or only the tags,
            // and absent has to mean "leave it" rather than "clear it".
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'tags' => ['sometimes', 'array', 'max:'.SideChat::MAX_TAGS],
            'tags.*' => ['string', 'max:'.SideChat::MAX_TAG_LENGTH],
        ];
    }
}
