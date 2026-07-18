<?php

namespace App\Http\Requests\Whiteboard;

use App\Models\SideChat;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Drawing on a side chat's whiteboard is a taking-part power, not a reading one: you have
 * to be on the roster, exactly like posting a message ({@see \App\Http\Requests\SideChat\
 * StoreSideChatMessageRequest}). Reading the board only needs channel membership and goes
 * through {@see \App\Http\Requests\SideChat\ViewSideChatRequest}.
 *
 * The base clears the roster gate; store adds the per-stroke validation on top.
 */
class ManageWhiteboardRequest extends FormRequest
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
        return [];
    }
}
