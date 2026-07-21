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
use App\Services\NicknameService;
use App\Services\Widgets\WidgetService;
use App\Support\Commands\CommandParser;
use App\Support\MentionParser;

final class SendMessageAction
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly LinkPreviewService $links,
        private readonly CommandParser $commands,
        private readonly WidgetService $widgets,
    ) {}

    /** @param  array<int, \Illuminate\Http\UploadedFile>  $files */
    public function handle(Channel $channel, User $user, SendMessageData $data, array $files = [], array $uploadIds = []): Message
    {
        // A message that's really a widget command (`m!p …`, `k!add …`) never lands as chat:
        // it drives the channel's music player or board instead. Only a text-only send can be
        // a command — anything with an attachment or a GIF is a plain message. See WidgetService.
        if ($files === [] && $uploadIds === [] && $data->gif === null && ($command = $this->commands->parse($data->body)) !== null) {
            return $this->widgets->handleCommand($channel, $user, $command);
        }

        $message = $channel->messages()->create([
            'user_id' => $user->id,
            'body' => $data->body,
            'reply_to_id' => $data->reply_to_id,
        ]);

        $this->attachments->storeFor($message, $files);
        // Large files came up in pieces and are already on disk; claiming moves them into place.
        $this->attachments->attachUploads($message, $uploadIds, $user);

        if ($data->gif !== null) {
            $this->attachments->storeGif($message, $data->gif);
        }

        // Any URL we haven't seen before unfurls on the queue and arrives over the
        // websocket a moment later — the send itself never waits on a third-party fetch.
        $this->links->syncFor($message);

        $message->load('user', 'replyTo.user', 'attachments', 'reactions.user', 'linkPreviews');

        broadcast(new MessageSent($message));
        // Wakes up the unread badge on this channel for everyone who isn't in it — and marks
        // that badge as a *mention* for anyone this message named (by @all or by name), so
        // their sidebar can call it out rather than treat it as one more unread.
        broadcast(new ChannelActivity($message, ...$this->mentioned($channel, $message, $user)));

        return $message;
    }

    /**
     * Who this message calls out by name (or by @all), resolved against the channel's roster.
     *
     * The author is dropped even if they named themselves — you don't get a mention badge
     * for your own message. @all is left as a flag rather than expanded to a list of ids:
     * every recipient of the broadcast is a member by definition, so "everyone" needs no
     * enumerating.
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
