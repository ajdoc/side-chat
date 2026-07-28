<?php

namespace App\Actions\Thread;

use App\Events\ThreadDeleted;
use App\Models\Thread;
use App\Services\AttachmentService;

final class DeleteThreadAction
{
    public function __construct(private readonly AttachmentService $attachments) {}

    /**
     * Delete a thread and every reply in it.
     *
     * The replies go by FK cascade, but their *files* don't — a cascade drops rows, not
     * bytes on disk — so they're purged first, exactly as DeleteMessageAction does when a
     * message takes its thread with it.
     *
     * The message the thread branched off is untouched. It goes on existing in the channel
     * timeline; all it loses is the "N replies" indicator, which the broadcast clears.
     */
    public function handle(Thread $thread): void
    {
        $threadId = $thread->id;
        $channelId = $thread->channel_id;
        $messageId = $thread->message_id;

        $this->attachments->purgeForMessages($thread->messages()->pluck('id')->all());

        $thread->delete();

        broadcast(new ThreadDeleted($threadId, $channelId, $messageId));
    }
}
