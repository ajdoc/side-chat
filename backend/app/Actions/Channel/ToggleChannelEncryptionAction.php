<?php

namespace App\Actions\Channel;

use App\Actions\Message\PostSystemMessageAction;
use App\Events\ChannelEncryptionToggled;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turns encryption on or off in a channel, and leaves a record that it happened.
 *
 * Turning it **on** increments the epoch, starting a fresh key era. Turning it **off**
 * leaves the epoch where it is — the era is over, and the next one gets its own number when
 * and if somebody starts it. Never reusing a number is what stops a member who was removed
 * during a plaintext gap from being able to read the era that follows it: their old sender
 * key belongs to an epoch nothing will ever be written under again.
 *
 * What this deliberately does *not* do is touch a single existing message. Turning
 * encryption on does not retroactively protect what was already said — that plaintext has
 * been on the server, in its backups and in its search index for as long as it has existed,
 * and re-encrypting it now would hide it from the UI while changing nothing about who has
 * already seen it. Turning encryption off cannot decrypt the era that just ended either:
 * the server never held those keys. History is what it was; only the future changes.
 *
 * The system message is the other half of the feature. Encryption makes a channel's
 * timeline striped, and a member scrolling through it needs to know which era they are
 * reading and who decided it — an invisible security boundary is one people misjudge.
 */
final class ToggleChannelEncryptionAction
{
    public function __construct(private readonly PostSystemMessageAction $system) {}

    public function handle(Channel $channel, User $user, bool $encrypted): Channel
    {
        // Flipping a switch to where it already is shouldn't burn an epoch or post a notice
        // saying nothing changed — two people hitting the same toggle is ordinary.
        if ($channel->isEncrypted() === $encrypted) {
            return $channel;
        }

        DB::transaction(function () use ($channel, $user, $encrypted) {
            $channel->forceFill([
                'encrypted' => $encrypted,
                'encryption_epoch' => $encrypted ? $channel->encryption_epoch + 1 : $channel->encryption_epoch,
                'encryption_toggled_by' => $user->id,
                'encryption_toggled_at' => now(),
            ])->save();
        });

        // Posted after the commit, and posted plaintext: it is a notice *about* the channel,
        // it names nobody's business, and a system message nobody can read is no notice at
        // all. SendMessageAction is what stamps member messages — this path bypasses it, so
        // the defaults (encrypted false, epoch null) stand, which is what we want.
        $this->system->handle($channel, $user, $this->notice($user, $encrypted));

        broadcast(new ChannelEncryptionToggled($channel));

        return $channel;
    }

    /**
     * Worded to say what actually changed, rather than "encryption enabled".
     *
     * The going-forward-only part is the thing people get wrong about this feature, and the
     * one line everybody in the channel is guaranteed to see is the cheapest place to say it.
     */
    private function notice(User $user, bool $encrypted): string
    {
        return $encrypted
            ? $user->name.' turned on end-to-end encryption. Messages from here on can only be read by people in this channel — earlier messages are unchanged.'
            : $user->name.' turned off end-to-end encryption. Messages from here on are readable by the server again — the encrypted ones stay unreadable.';
    }
}
