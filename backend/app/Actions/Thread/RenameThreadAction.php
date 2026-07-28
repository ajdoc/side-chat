<?php

namespace App\Actions\Thread;

use App\Events\ThreadUpdated;
use App\Models\Thread;

final class RenameThreadAction
{
    /**
     * Retitle a thread.
     *
     * Reuses ThreadUpdated, the event UpdateMessageAction already fires when editing a
     * parent message changes the title it derived — one event means the timeline
     * indicator, the Threads list and the open panel header all update from a single
     * handler however the name came to change.
     */
    public function handle(Thread $thread, string $name): Thread
    {
        $thread->update(['name' => $name]);

        $thread->load(['creator', 'parentMessage.user'])->loadCount('messages');

        broadcast(new ThreadUpdated($thread));

        return $thread;
    }
}
