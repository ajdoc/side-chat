<?php

namespace App\Http\Requests\SideChat;

use App\Http\Requests\MemberRequest;
use App\Models\SideChat;

/**
 * Retitle, retag or delete a post — the OP's own, or the server staff's. Shared base,
 * because all three are the same question about the same post.
 *
 * The rule lives on the model ({@link SideChat::canManage}) so SideChatResource can hand
 * the client the same answer and draw only the buttons the server would honour.
 */
abstract class ManageSideChatRequest extends MemberRequest
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        $sideChat = $this->route('sideChat');
        $user = $this->user();

        return $sideChat instanceof SideChat && $user !== null && $sideChat->canManage($user);
    }
}
