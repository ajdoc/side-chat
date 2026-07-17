<?php

namespace App\Http\Requests\SideChat;

use App\Models\SideChat;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding people to a side chat is a power you get by being in it: the caller must already
 * be on the roster. Who they may add is checked in the action against the channel's members
 * (you can only bring in people who can already be here).
 */
class AddParticipantsRequest extends FormRequest
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
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer'],
        ];
    }
}
