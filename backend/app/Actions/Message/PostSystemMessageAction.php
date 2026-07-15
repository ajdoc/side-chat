<?php

namespace App\Actions\Message;

use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;

/** Posts a generated message (e.g. "X joined the server") and broadcasts it live. */
final class PostSystemMessageAction
{
    public function handle(Channel $channel, User $user, string $body): Message
    {
        $message = $channel->messages()->create([
            'user_id' => $user->id,
            'body' => $body,
            'type' => 'system',
        ]);

        $message->load('user', 'replyTo.user', 'attachments');

        broadcast(new MessageSent($message));

        return $message;
    }
}
