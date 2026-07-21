<?php

namespace App\Actions\Message;

use App\Events\ChannelActivity;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\LinkPreviewService;
use App\Services\NicknameService;
use App\Support\MentionParser;

/**
 * Forward an existing message into another channel — a DM, a group chat, or a server
 * channel — as a fresh message from the forwarding user.
 *
 * Deliberately *not* a call into {@see SendMessageAction}: a forward copies the source's
 * text and files verbatim, so it must never be re-parsed as a widget command (`m!…`) or
 * have its reply-reference carried across into a channel where that message doesn't exist.
 * Its `forwarded_from_id` is what earns it the "Forwarded from X" line.
 */
final class ForwardMessageAction
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly LinkPreviewService $links,
    ) {}

    public function handle(Message $source, Channel $target, User $user): Message
    {
        $message = $target->messages()->create([
            'user_id' => $user->id,
            'body' => $source->body,
            'forwarded_from_id' => $source->id,
        ]);

        // Copy the source's files across into the target channel's own storage.
        $this->attachments->cloneForMessage($message, $source->attachments()->get());

        // Unfurl any links in the copied body against the new message — the previews aren't
        // carried over, they're regenerated so they belong to this message.
        $this->links->syncFor($message);

        $message->load('user', 'forwardedFrom.user', 'attachments', 'reactions.user', 'linkPreviews');

        broadcast(new MessageSent($message));
        broadcast(new ChannelActivity($message, ...$this->mentioned($target, $message, $user)));

        return $message;
    }

    /**
     * Who the forwarded text names in its *new* home — resolved against the target channel's
     * roster, not the source's, since an @name only means someone here if they're a member
     * here. Same shape and rules as {@see SendMessageAction::mentioned()}.
     *
     * @return array{mentionsAll: bool, mentionedUserIds: array<int, int>}
     */
    private function mentioned(Channel $channel, Message $message, User $author): array
    {
        $container = $channel->container();
        if ($container === null || $message->body === null) {
            return ['mentionsAll' => false, 'mentionedUserIds' => []];
        }

        // Every name each member answers to here — their own, plus the nickname they go
        // by in this place. See NicknameService::mentionNamesFor.
        $names = app(NicknameService::class)->mentionNamesFor($container);
        $parsed = MentionParser::parse($message->body, $names);

        $userIds = array_values(array_filter(
            $parsed['user_ids'],
            fn (int $id) => $id !== $author->id,
        ));

        return ['mentionsAll' => $parsed['all'], 'mentionedUserIds' => $userIds];
    }
}
