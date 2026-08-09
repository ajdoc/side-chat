<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\DeviceToken;
use App\Models\Message;
use App\Models\User;
use App\Services\Notifications\FcmSender;
use App\Services\Notifications\NotificationPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Buzzes the phones of everyone who asked to hear about this message.
 *
 * Runs on the queue rather than in the request, for the usual reason and one specific one:
 * sending is one HTTP call to Google per device, and a send in a busy channel would
 * otherwise make the *sender* wait through all of them.
 *
 * It deliberately does **not** ask whether the recipient is currently online. With the
 * desktop app sitting in the tray all day, "has a live websocket" is true for most people
 * most of the time, and suppressing on it would mean a phone that never rings. The device
 * itself is the only thing that knows whether the app is actually in front of you, so the
 * decision to stay quiet is made there — see the Android handler in the mobile shell.
 */
class SendPushNotifications implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, int>  $mentionedUserIds
     */
    public function __construct(
        public int $messageId,
        public array $mentionedUserIds = [],
        public bool $mentionsAll = false,
    ) {}

    public function handle(FcmSender $sender, NotificationPolicy $policy): void
    {
        if (! $sender->configured()) {
            return;
        }

        $message = Message::with('user', 'channel.server', 'channel.conversation', 'channel.parent')->find($this->messageId);

        // Deleted between sending and this running. Notifying about it now would be worse
        // than not notifying at all.
        if ($message === null || $message->channel === null) {
            return;
        }

        $recipients = $this->recipients($message, $policy);

        if ($recipients === []) {
            return;
        }

        $devices = DeviceToken::query()
            ->whereIn('user_id', $recipients)
            ->whereIn('platform', ['android', 'ios'])
            ->get();

        if ($devices->isEmpty()) {
            return;
        }

        $sender->send($devices, $this->payload($message));
    }

    /**
     * Everyone who should be told, in the order the gates have to be applied.
     *
     * Membership of the container is not enough on its own: a private channel has its own
     * allow-list, and a discussion inherits its parent's. `hasMember` is the one method
     * that knows all of that, so access is asked of it per user rather than reimplemented
     * here with a join that would drift from it.
     *
     * @return array<int, int>
     */
    private function recipients(Message $message, NotificationPolicy $policy): array
    {
        $channel = $message->channel;
        $container = $channel->container();

        if ($container === null) {
            return [];
        }

        $candidates = User::query()
            ->whereIn('id', $container->memberIds())
            ->whereKeyNot($message->user_id)   // you know what you just said
            ->where('is_bot', false)           // a bot has no phone
            ->where('push_enabled', true)
            ->get();

        $allowed = $candidates
            ->filter(fn (User $user) => $channel->hasMember($user))
            ->pluck('id')
            ->all();

        return $policy->recipients($channel, $allowed, $this->mentionedUserIds, $this->mentionsAll);
    }

    /**
     * What the phone gets. Strings only — FCM rejects any other type in a data payload.
     *
     * @return array<string, string>
     */
    private function payload(Message $message): array
    {
        $channel = $message->channel;

        return [
            'type' => 'message',
            'message_id' => (string) $message->id,
            'channel_id' => (string) $channel->id,
            'conversation_id' => (string) ($channel->conversation_id ?? ''),
            'server_id' => (string) ($channel->server_id ?? ''),
            // Also read back out by FcmSender to build the visible notification — Android
            // draws that itself for a backgrounded app, which is the only reason a closed
            // app ever shows anything. See that class for why it isn't data-only.
            'title' => $this->title($message),
            'body' => $this->body($message),
            // Collapses repeat messages from one place into a single alert on the device.
            'tag' => 'channel-'.$channel->id,
        ];
    }

    private function title(Message $message): string
    {
        $channel = $message->channel;
        $sender = $message->user?->name ?? 'Someone';

        // A DM is from a person; a channel is a place that a person spoke in. Titling both
        // the same way loses whichever half matters.
        if ($channel->conversation !== null) {
            return $channel->conversation->type === 'group'
                ? sprintf('%s — %s', $sender, $channel->conversation->name ?? 'Group')
                : $sender;
        }

        return sprintf('%s — #%s', $sender, $channel->name);
    }

    /**
     * A preview, unless there can't be one.
     *
     * In an encrypted channel the server holds ciphertext and nothing else, so there is
     * genuinely nothing to preview — the alert can only say that something arrived. Same
     * for an attachment-only message, which has no text to show in the first place.
     */
    private function body(Message $message): string
    {
        if ($message->isEncrypted()) {
            return 'Sent you an encrypted message';
        }

        $body = trim((string) $message->body);

        if ($body === '') {
            return 'Sent an attachment';
        }

        return Str::limit($body, 140);
    }
}
