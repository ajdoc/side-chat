<?php

namespace App\Actions\SideChat;

use App\Events\SideChatActivity;
use App\Models\SideChat;
use App\Models\User;
use App\Services\SideChatService;

final class LeaveSideChatAction
{
    public function __construct(private readonly SideChatService $sideChats) {}

    public function handle(SideChat $sideChat, User $user): SideChat
    {
        $sideChat->participants()->detach($user->id);

        $this->sideChats->loadForDisplay($sideChat);

        broadcast(new SideChatActivity($sideChat));

        return $sideChat;
    }
}
