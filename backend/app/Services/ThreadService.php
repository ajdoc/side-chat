<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\SideChat;
use App\Models\Thread;
use Illuminate\Database\Eloquent\Collection;

final class ThreadService
{
    /**
     * The channel's own threads — not the ones belonging to its side chats. A side-chat
     * thread lives in that side chat's workspace, so it's kept out of the channel's list.
     *
     * @return Collection<int, Thread>
     */
    public function forChannel(Channel $channel): Collection
    {
        return $channel->threads()
            ->whereNull('side_chat_id')
            ->with(['creator', 'parentMessage.user'])
            ->withCount('messages')
            ->get();
    }

    /** @return Collection<int, Thread> */
    public function forSideChat(SideChat $sideChat): Collection
    {
        return $sideChat->threads()
            ->with(['creator', 'parentMessage.user'])
            ->withCount('messages')
            ->get();
    }

    /** Eager-loads everything ThreadResource needs. */
    public function loadForDisplay(Thread $thread): Thread
    {
        return $thread->load(['creator', 'parentMessage.user'])->loadCount('messages');
    }
}
