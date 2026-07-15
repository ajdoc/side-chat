<?php

namespace App\Actions\Message;

use App\DTOs\Message\SendMessageData;
use App\Events\ChannelActivity;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\LinkPreviewService;

final class SendMessageAction
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly LinkPreviewService $links,
    ) {}

    /** @param  array<int, \Illuminate\Http\UploadedFile>  $files */
    public function handle(Channel $channel, User $user, SendMessageData $data, array $files = []): Message
    {
        $message = $channel->messages()->create([
            'user_id' => $user->id,
            'body' => $data->body,
            'reply_to_id' => $data->reply_to_id,
        ]);

        $this->attachments->storeFor($message, $files);

        // Any URL we haven't seen before unfurls on the queue and arrives over the
        // websocket a moment later — the send itself never waits on a third-party fetch.
        $this->links->syncFor($message);

        $message->load('user', 'replyTo.user', 'attachments', 'reactions.user', 'linkPreviews');

        broadcast(new MessageSent($message));
        // Wakes up the unread badge on this channel for everyone who isn't in it.
        broadcast(new ChannelActivity($message));

        return $message;
    }
}
