<?php

namespace App\Http\Requests\SideChat;

use App\Models\Message;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Recording a decision is a side-chat power: the message must live in one, and the caller
 * must have joined that side chat's roster.
 */
class ToggleDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $message = $this->route('message');
        $user = $this->user();

        if (! $message instanceof Message || $user === null || $message->side_chat_id === null) {
            return false;
        }

        return $message->loadMissing('sideChat')->sideChat?->hasParticipant($user) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
