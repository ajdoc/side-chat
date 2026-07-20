<?php

namespace App\Http\Requests\Canvas;

use App\Models\SideChat;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create, change or delete a card on a side chat's Open Canvas — a taking-part power, so you
 * have to be on the roster, exactly like posting a message or drawing on the board
 * ({@see \App\Http\Requests\Whiteboard\ManageWhiteboardRequest}). Reading the canvas only
 * needs channel membership ({@see \App\Http\Requests\SideChat\ViewSideChatRequest}).
 *
 * One class serves store, update and destroy; the rules are empty on DELETE.
 */
class CanvasItemRequest extends FormRequest
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
        if ($this->isMethod('delete')) {
            return [];
        }

        return CanvasItemRules::forMethod($this->isMethod('post'));
    }
}
