<?php

namespace App\Http\Requests\Whiteboard;

use App\Models\SideChat;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rearranging a side chat's board layers — a taking-part power, so you have to be on the
 * roster, exactly as with its strokes and its desk tabs.
 */
class SideChatBoardLayersRequest extends FormRequest
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
        return BoardLayerRules::rules();
    }
}
