<?php

namespace App\Http\Requests\Space;

use App\Models\SideChat;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saving a side chat's note is a taking-part power, not a reading one: you have to be on the
 * roster, exactly like posting a message or drawing on the board ({@see \App\Http\Requests\
 * Whiteboard\ManageWhiteboardRequest}). Reading the note only needs channel membership and
 * goes through {@see \App\Http\Requests\SideChat\ViewSideChatRequest}.
 */
class UpdateSpaceNoteRequest extends FormRequest
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
        return [
            'content' => ['nullable', 'string', 'max:100000'],
            // The revision this edit was typed on top of. Omit it to save unconditionally.
            'base_version' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
