<?php

namespace App\Actions\SideChat;

use App\DTOs\Message\SendMessageData;
use App\Events\MessageSent;
use App\Events\SideChatActivity;
use App\Models\Message;
use App\Models\SideChat;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\LinkPreviewService;

final class SendSideChatMessageAction
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly LinkPreviewService $links,
    ) {}

    /** @param  array<int, \Illuminate\Http\UploadedFile>  $files */
    public function handle(SideChat $sideChat, User $user, SendMessageData $data, array $files = []): Message
    {
        $message = $sideChat->messages()->create([
            // The channel id rides along so pins, attachments and container-membership
            // resolution all work exactly as they do for a channel or thread message.
            'channel_id' => $sideChat->channel_id,
            'user_id' => $user->id,
            'body' => $data->body,
            'reply_to_id' => $data->reply_to_id,
        ]);

        $this->attachments->storeFor($message, $files);
        $this->links->syncFor($message);

        $message->load('user', 'replyTo.user', 'attachments', 'reactions.user', 'comments.user', 'linkPreviews');

        broadcast(new MessageSent($message));         // side chat stream
        broadcast(new SideChatActivity($sideChat));   // channel + side chat streams (live card)

        return $message;
    }
}
