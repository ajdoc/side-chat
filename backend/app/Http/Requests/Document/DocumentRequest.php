<?php

namespace App\Http\Requests\Document;

use App\Models\SideChat;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload or delete a document on a side chat's Docs app — a taking-part power, so you have to
 * be on the roster, exactly like posting a message ({@see \App\Http\Requests\Whiteboard\
 * ManageWhiteboardRequest}). Reading the list only needs channel membership
 * ({@see \App\Http\Requests\SideChat\ViewSideChatRequest}).
 *
 * One class serves store and destroy; the file rule applies only on upload (POST).
 */
class DocumentRequest extends FormRequest
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
        return $this->isMethod('post') ? DocumentRules::upload() : [];
    }
}
