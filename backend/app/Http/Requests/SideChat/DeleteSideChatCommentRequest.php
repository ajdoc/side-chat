<?php

namespace App\Http\Requests\SideChat;

use App\Models\SideChatComment;
use Illuminate\Foundation\Http\FormRequest;

/** Only the person who left a comment may remove it — the message-level rule, unchanged. */
class DeleteSideChatCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $comment = $this->route('comment');

        return $comment instanceof SideChatComment
            && $this->user() !== null
            && $comment->user_id === $this->user()->id;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
