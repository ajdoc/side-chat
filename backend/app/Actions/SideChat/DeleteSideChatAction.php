<?php

namespace App\Actions\SideChat;

use App\Events\SideChatDeleted;
use App\Models\Message;
use App\Models\SideChat;
use App\Services\AttachmentService;

final class DeleteSideChatAction
{
    public function __construct(private readonly AttachmentService $attachments) {}

    /**
     * Delete a post and everything inside it: its timeline, its threads, its roster, its
     * board and notes and canvas — all of which cascade off the row by foreign key.
     *
     * Files don't cascade, so they're purged first, the same way DeleteMessageAction and
     * DeleteThreadAction do it. That's two sets, not one: a thread reply carries the
     * thread's id but *not* the side chat's (see SendThreadMessageAction), so the side
     * chat's own timeline doesn't contain them and they have to be gathered separately —
     * otherwise deleting a busy post leaves every thread attachment orphaned on disk.
     *
     * The origin message in the channel survives — it's a message in its own right, and
     * all it loses is the card pointing here, which the broadcast clears.
     */
    public function handle(SideChat $sideChat): void
    {
        $sideChatId = $sideChat->id;
        $channelId = $sideChat->channel_id;
        $messageId = $sideChat->message_id;

        $messageIds = array_merge(
            $sideChat->messages()->pluck('id')->all(),
            Message::whereIn('thread_id', $sideChat->threads()->select('id'))->pluck('id')->all(),
        );

        $this->attachments->purgeForMessages($messageIds);

        $sideChat->delete();

        broadcast(new SideChatDeleted($sideChatId, $channelId, $messageId));
    }
}
