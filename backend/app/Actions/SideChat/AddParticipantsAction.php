<?php

namespace App\Actions\SideChat;

use App\Events\SideChatActivity;
use App\Models\SideChat;
use App\Services\SideChatService;

final class AddParticipantsAction
{
    public function __construct(private readonly SideChatService $sideChats) {}

    /**
     * Add people to a side chat's roster.
     *
     * You can only bring in people who are already in the channel — the requested ids are
     * intersected with the container's members, so a stray or non-member id is silently
     * dropped rather than trusted. Already-present participants are a no-op (syncWithout
     * Detaching), so re-adding someone never demotes an owner or duplicates a row.
     *
     * @param  array<int, int>  $userIds
     */
    public function handle(SideChat $sideChat, array $userIds): SideChat
    {
        $container = $sideChat->channel->container();

        $eligible = $container === null
            ? []
            : $container->members()->whereIn('users.id', $userIds)->pluck('users.id')->all();

        if ($eligible !== []) {
            $sideChat->participants()->syncWithoutDetaching(
                array_fill_keys($eligible, ['role' => 'member'])
            );
        }

        $this->sideChats->loadForDisplay($sideChat);

        broadcast(new SideChatActivity($sideChat));

        return $sideChat;
    }
}
