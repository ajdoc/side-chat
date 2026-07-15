<?php

namespace App\Actions\Attachment;

use App\Events\MessageUpdated;
use App\Models\Attachment;
use App\Models\Message;
use App\Services\AttachmentService;
use Illuminate\Support\Collection;

final class DeleteAttachmentAction
{
    public function __construct(private readonly AttachmentService $attachments) {}

    /** Removes one attachment (and its file), then re-broadcasts the owning message. */
    public function handle(Attachment $attachment): Message
    {
        $message = $attachment->message()->firstOrFail();

        $this->attachments->purge(new Collection([$attachment]));

        $message->load('user', 'replyTo.user', 'attachments');

        broadcast(new MessageUpdated($message));

        return $message;
    }
}
