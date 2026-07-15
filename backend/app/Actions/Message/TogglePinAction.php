<?php

namespace App\Actions\Message;

use App\Events\MessagePinToggled;
use App\Models\Message;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class TogglePinAction
{
    /**
     * Pin a message, or unpin it if it's already pinned.
     *
     * A toggle rather than a pair of endpoints, for the same reason reactions are one: two
     * people pinning the same message at the same moment should converge, not race, and the
     * broadcast carries the resulting state rather than the delta so a client that missed an
     * event still lands in the right place.
     *
     * Unpinning is deliberately open to any member, not just whoever pinned it — a pin is a
     * statement about the channel, not about the person who made it, and needing to chase
     * the original pinner is how channels end up with a stale pinned list nobody can clear.
     */
    public function handle(Message $message, User $user): Message
    {
        if ($message->isSystem()) {
            throw ValidationException::withMessages([
                'message' => 'System messages cannot be pinned.',
            ]);
        }

        $message->forceFill($message->isPinned()
            ? ['pinned_at' => null, 'pinned_by' => null]
            : ['pinned_at' => now(), 'pinned_by' => $user->id]);

        $message->save();

        $message->load('user', 'replyTo.user', 'attachments', 'reactions.user', 'linkPreviews', 'pinner');

        broadcast(new MessagePinToggled($message));

        return $message;
    }
}
