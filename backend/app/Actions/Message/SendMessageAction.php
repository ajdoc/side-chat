<?php

namespace App\Actions\Message;

use App\DTOs\Message\SendMessageData;
use App\Events\ChannelActivity;
use App\Events\MessageSent;
use App\Jobs\SendPushNotifications;
use App\Services\Notifications\FcmSender;
use App\Models\BotSettings;
use App\Models\Channel;
use App\Models\CustomCommand;
use App\Models\Message;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\Commands\CustomCommandService;
use App\Services\Commands\SlashCommandService;
use App\Services\LinkPreviewService;
use App\Services\NicknameService;
use App\Services\Widgets\WidgetService;
use App\Support\Commands\CommandParser;
use App\Support\Commands\EphemeralMessage;
use App\Support\Commands\SlashOutcome;
use App\Support\MentionParser;
use Illuminate\Http\UploadedFile;

final class SendMessageAction
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly LinkPreviewService $links,
        private readonly CommandParser $commands,
        private readonly WidgetService $widgets,
        private readonly SlashCommandService $slash,
        private readonly CustomCommandService $custom,
    ) {}

    /** @param  array<int, UploadedFile>  $files */
    public function handle(Channel $channel, User $user, SendMessageData $data, array $files = [], array $uploadIds = []): Message
    {
        // A message that's really a widget command (`m!p …`, `k!add …`) never lands as chat:
        // it drives the channel's music player or board instead. Only a text-only send can be
        // a command — anything with an attachment or a GIF is a plain message. See WidgetService.
        $body = $data->body;

        // In an encrypted channel the body is ciphertext, so none of the command shapes can
        // be recognised — and mustn't be guessed at. A base64 envelope that happens to start
        // with a prefix character is not a command, and running one would answer a message
        // nobody sent. Commands come back the moment encryption is turned off.
        /*
         * A guest's message is only ever a message.
         *
         * Commands are the app's other door into a room: `k!add` files a card, `a!board` opens a
         * surface, a slash command can reach a bot. All of that is refused to a guest over HTTP
         * (see ConfineGuests), and a guest who could do it by typing would make that gate
         * decorative. So what they typed is posted as what they typed — visible, harmless, and
         * honest about having done nothing.
         */
        $isPlainText = ! $channel->isEncrypted()
            && ! $user->is_guest
            && $files === [] && $uploadIds === [] && $data->gif === null;

        if ($isPlainText && ($command = $this->commands->parse($body)) !== null) {
            if ($command->namespace !== CommandParser::SLASH_NAMESPACE) {
                return $this->widgets->handleCommand($channel, $user, $command);
            }

            $outcome = $this->slash->handle($channel, $user, $command);

            // A private answer stops here — nothing is written and nothing is broadcast.
            if ($outcome->ephemeral !== null) {
                return EphemeralMessage::make($channel, $user, $outcome->ephemeral);
            }

            // A public one falls through to the ordinary send with the *result* in place of
            // the instruction, so a roll or an emote is a real message in every respect:
            // it broadcasts, it can be replied to, it survives a reload. See SlashOutcome.
            $body = $outcome->body;
        } elseif ($isPlainText && ($outcome = $this->prefixed($channel, $user, $body)) !== null) {
            if ($outcome->ephemeral !== null) {
                return EphemeralMessage::make($channel, $user, $outcome->ephemeral);
            }

            $body = $outcome->body;
        }

        // Stamped from the channel as it is *at this instant*, and never read back off the
        // channel afterwards. The setting can be turned off tomorrow; what this message is
        // can't change, because nobody can un-encrypt what was sent encrypted.
        $message = $channel->messages()->create([
            'user_id' => $user->id,
            'body' => $body,
            'encrypted' => $channel->isEncrypted(),
            'epoch' => $channel->currentEpoch(),
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
        // Skipped entirely when encrypted: there is no URL to find in ciphertext, and the
        // one thing worse than no preview would be the server reaching out to a third party
        // about a link it was never meant to see.
        if (! $message->isEncrypted()) {
            $this->links->syncFor($message);
        }

        $message->load('user', 'replyTo.user', 'attachments', 'reactions.user', 'linkPreviews');

        $mentioned = $this->mentioned($channel, $message, $user);

        broadcast(new MessageSent($message));
        // Wakes up the unread badge on this channel for everyone who isn't in it — and marks
        // that badge as a *mention* for anyone this message named (by @all or by name), so
        // their sidebar can call it out rather than treat it as one more unread.
        broadcast(new ChannelActivity($message, ...$mentioned));
        // Same audience, different reach: the badge only lands on a client that's connected,
        // and the whole point of a push is the people who aren't. Who actually gets one is
        // NotificationPolicy's call, not this one's.
        //
        // Guarded on push being configured at all, so an install without FCM credentials
        // doesn't queue a job per message for a sender that would immediately give up.
        SendPushNotifications::dispatchIf(
            FcmSender::enabled(),
            $message->id,
            $mentioned['mentionedUserIds'],
            $mentioned['mentionsAll'],
        );

        return $message;
    }

    /**
     * `!rules` — a command this server invented, on the prefix it chose.
     *
     * Last of the command shapes to be tried, and the only one that needs a database read to
     * *recognise*: the prefix is per-server configuration, so the string alone can't say
     * whether `!rules` is a command here. The read is gated twice — a cheap string test that
     * rejects almost every message, then a cached prefix lookup — so an ordinary "hello"
     * costs one preg_match and nothing else.
     *
     * Returns null for anything that isn't one, including a prefix that matched no command:
     * unlike a slash, a stray `!` is far more likely to be punctuation than a typo, so it
     * falls through and posts as written rather than being answered with "no such command".
     */
    private function prefixed(Channel $channel, User $user, ?string $body): ?SlashOutcome
    {
        if ($channel->server_id === null || ! CommandParser::mightBePrefixed($body)) {
            return null;
        }

        $parsed = $this->commands->parsePrefixed($body, BotSettings::prefixFor($channel->server_id));

        if ($parsed === null) {
            return null;
        }

        $command = $this->custom->find($channel->server_id, $parsed->verb, CustomCommand::PREFIX);

        return $command === null ? null : $this->custom->run($command, $channel, $user);
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

        // Ciphertext names nobody. The unread badge still lights up — that's ChannelActivity's
        // job and it doesn't need the body — but it lights up as an ordinary unread rather
        // than a mention. Working out who was called out is the client's to do once it has
        // decrypted, and until it can tell the server that without handing back the plaintext,
        // an encrypted mention is a quieter notification than an unencrypted one. Say so in
        // the UI rather than pretending otherwise.
        if ($message->isEncrypted()) {
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
