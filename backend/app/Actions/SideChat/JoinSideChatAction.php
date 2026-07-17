<?php

namespace App\Actions\SideChat;

use App\Events\SideChatActivity;
use App\Models\SideChat;
use App\Models\User;
use App\Services\SideChatService;

final class JoinSideChatAction
{
    public function __construct(private readonly SideChatService $sideChats) {}

    public function handle(SideChat $sideChat, User $user): SideChat
    {
        // Idempotent: joining twice is a no-op, and never demotes an existing owner.
        $sideChat->participants()->syncWithoutDetaching([$user->id => ['role' => 'member']]);

        $this->sideChats->loadForDisplay($sideChat);

        broadcast(new SideChatActivity($sideChat));

        return $sideChat;
    }
}
