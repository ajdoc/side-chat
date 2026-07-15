<?php

namespace App\Actions\Reaction;

use App\DTOs\Reaction\ToggleReactionData;
use App\Events\ReactionToggled;
use App\Models\Message;
use App\Models\User;

final class ToggleReactionAction
{
    /**
     * Add the reaction, or remove it if this user already reacted with that emoji.
     *
     * Broadcast (unlike a new message) goes to *everyone*, the reactor included: the
     * payload is the full summary, so re-applying it is a no-op for whoever already
     * has it — and that costs less than threading a socket id through to skip them.
     */
    public function handle(Message $message, User $user, ToggleReactionData $data): Message
    {
        $existing = $message->reactions()
            ->where('user_id', $user->id)
            ->where('emoji', $data->emoji)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            $message->reactions()->create([
                'user_id' => $user->id,
                'emoji' => $data->emoji,
            ]);
        }

        $message->load('reactions.user');

        broadcast(new ReactionToggled($message));

        return $message;
    }
}
