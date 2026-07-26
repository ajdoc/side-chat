<?php

namespace App\Http\Requests\DeskApps;

use App\Models\SideChat;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rearrange a side chat's Side Desk tabs — a taking-part power, so you have to be on the
 * roster, exactly as with the canvas and the board.
 */
class SideChatDeskAppsRequest extends FormRequest
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
        return DeskAppsRules::rules();
    }
}
