<?php

namespace App\Http\Requests\SideChatForum;

use Illuminate\Validation\Rule;

/**
 * The whole running order in one call, as an array of forum ids top to bottom.
 *
 * A list rather than one position per request, because dragging a group from the bottom to
 * the top renumbers every group between — sending that as N requests means N chances to end
 * up with an order nobody asked for.
 */
class ReorderSideChatForumsRequest extends ManageSideChatForumRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array'],
            'ids.*' => [
                'integer',
                // Scoped to this channel: ids from elsewhere would silently adopt another
                // channel's groups into this list.
                Rule::exists('side_chat_forums', 'id')->where('channel_id', $this->route('channel')->id),
            ],
        ];
    }
}
