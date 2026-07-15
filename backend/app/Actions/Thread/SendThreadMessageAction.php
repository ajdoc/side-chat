<?php

namespace App\Actions\Thread;

use App\DTOs\Message\SendMessageData;
use App\Events\MessageSent;
use App\Events\ThreadActivity;
use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\LinkPreviewService;

final class SendThreadMessageAction
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly LinkPreviewService $links,
    ) {}

    /** @param  array<int, \Illuminate\Http\UploadedFile>  $files */
    public function handle(Thread $thread, User $user, SendMessageData $data, array $files = []): Message
    {
        $message = $thread->messages()->create([
            'channel_id' => $thread->channel_id,
            'user_id' => $user->id,
            'body' => $data->body,
            'reply_to_id' => $data->reply_to_id,
        ]);

        $this->attachments->storeFor($message, $files);
        $this->links->syncFor($message);

        $message->load('user', 'replyTo.user', 'attachments', 'reactions.user', 'linkPreviews');

        broadcast(new MessageSent($message));    // thread stream
        broadcast(new ThreadActivity($thread));  // channel stream (live reply count)

        return $message;
    }
}
