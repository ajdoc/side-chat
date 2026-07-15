<?php

namespace App\Actions\Message;

use App\DTOs\Message\UpdateMessageData;
use App\Events\MessageUpdated;
use App\Events\ThreadUpdated;
use App\Models\Message;
use App\Services\AttachmentService;
use App\Services\LinkPreviewService;
use Illuminate\Support\Str;

final class UpdateMessageAction
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly LinkPreviewService $links,
    ) {}

    /**
     * Edits a message.
     *  - Attachments listed in `remove_attachment_ids` are deleted from disk (rule: replacing
     *    or removing a file physically removes the old one).
     *  - Any newly uploaded files are added.
     *  - If the message started a thread, the thread title is kept in sync (business rule 2).
     *
     * @param  array<int, \Illuminate\Http\UploadedFile>  $files
     */
    public function handle(Message $message, UpdateMessageData $data, array $files = []): Message
    {
        $message->update([
            'body' => $data->body,
            'edited_at' => now(),
        ]);

        // Physically delete the files the user removed/replaced.
        $this->attachments->purgeByIds($message, $data->remove_attachment_ids ?? []);
        $this->attachments->storeFor($message, $files);

        // The edit may have added, removed, or reordered links.
        $this->links->syncFor($message);

        $message->loadMissing('startedThread');

        if (($thread = $message->startedThread) && filled($data->body)) {
            $thread->update(['name' => Str::limit((string) $data->body, 100, '')]);
            broadcast(new ThreadUpdated($thread));
        }

        $message->load('user', 'replyTo.user', 'reactions.user');
        $message->load('attachments', 'linkPreviews'); // refresh after add/remove

        broadcast(new MessageUpdated($message));

        return $message;
    }
}
