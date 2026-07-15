<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Thread;
use Illuminate\Database\Eloquent\Collection;

final class ThreadService
{
    /** @return Collection<int, Thread> */
    public function forChannel(Channel $channel): Collection
    {
        return $channel->threads()
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
