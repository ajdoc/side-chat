<?php

namespace App\Actions\Document;

use App\Events\ChannelActivity;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\SpaceDocument;
use App\Models\User;
use App\Services\AttachmentService;

/**
 * Share a Side Space shelf document into the channel timeline — the "Send to chat" action on
 * a Docs card. It posts a plain message carrying a *copy* of the file as an attachment, so
 * the file now lives in chat exactly like one dragged into the composer: it shows in the
 * timeline and in Info → Files, and stays independent of the shelf original.
 *
 * Mirrors the tail of {@see \App\Actions\Message\SendMessageAction} — create, attach,
 * broadcast MessageSent and ChannelActivity — minus the command/link/mention handling a
 * document share has no use for.
 */
final class ShareSpaceDocumentAction
{
    public function __construct(private readonly AttachmentService $attachments) {}

    public function handle(Channel $channel, User $user, SpaceDocument $document): Message
    {
        $message = $channel->messages()->create([
            'user_id' => $user->id,
            'body' => null,
        ]);

        $this->attachments->attachStoredFile(
            $message,
            $document->disk,
            $document->path,
            $document->name,
            $document->mime_type,
            $document->extension,
            $document->size,
        );

        $message->load('user', 'replyTo.user', 'attachments', 'reactions.user', 'linkPreviews');

        broadcast(new MessageSent($message));
        // Wake the unread badge for everyone not looking; a document share names no one.
        broadcast(new ChannelActivity($message));

        return $message;
    }
}
