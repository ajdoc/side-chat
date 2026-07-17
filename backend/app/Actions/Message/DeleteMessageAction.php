<?php

namespace App\Actions\Message;

use App\Events\MessageDeleted;
use App\Events\ThreadDeleted;
use App\Models\Message;
use App\Services\AttachmentService;
use Illuminate\Support\Facades\DB;

final class DeleteMessageAction
{
    public function __construct(private readonly AttachmentService $attachments) {}

    /**
     * Deletes a message. If it started a thread, the thread and all of its replies go with
     * it (business rule 3) — and every attachment file for all of those messages is removed
     * from disk first, since a DB cascade would otherwise leave orphaned files behind.
     */
    public function handle(Message $message): void
    {
        $message->loadMissing('startedThread');

        $channelId = $message->channel_id;
        $threadId = $message->thread_id;
        $sideChatId = $message->side_chat_id;
        $messageId = $message->id;
        $startedThread = $message->startedThread;
        $startedThreadId = $startedThread?->id;

        // Every message whose files must be purged: this one + the whole thread it started.
        $messageIds = [$messageId];
        if ($startedThread !== null) {
            $messageIds = array_merge(
                $messageIds,
                $startedThread->messages()->pluck('id')->all()
            );
        }

        $this->attachments->purgeForMessages($messageIds);

        DB::transaction(function () use ($message, $startedThread): void {
            $startedThread?->delete(); // cascades the thread's messages via FK
            $message->delete();
        });

        if ($startedThreadId !== null) {
            broadcast(new ThreadDeleted($startedThreadId, $channelId, $messageId));
        }

        broadcast(new MessageDeleted($messageId, $channelId, $threadId, $sideChatId));
    }
}
