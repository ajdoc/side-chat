<?php

namespace App\Support\Commands;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;

/**
 * A note only the person who typed the command sees.
 *
 * Deliberately unsaved and unbroadcast: it exists only in the HTTP response the sender gets
 * back, so their client shows it and a reload forgets it. Help text, "no such command" and
 * "no card #3" are answers to one person's question — putting them in the channel would
 * mean everybody reading somebody else's typo.
 *
 * The negative id keeps it from ever colliding with a real message row, which matters
 * because the client keys its timeline by id.
 *
 * Shared by the widget commands (`k!add`) and the slash commands (`/roll`) — the two
 * families answer differently but this part of answering is identical, and two copies of it
 * would drift.
 */
final class EphemeralMessage
{
    public static function make(Channel $channel, User $user, string $body): Message
    {
        $message = new Message([
            'channel_id' => $channel->id,
            'user_id' => $user->id,
            'body' => $body,
            'type' => 'system',
        ]);

        $message->id = -(int) round(microtime(true) * 1000);
        $message->created_at = now();
        $message->updated_at = now();
        $message->setRelation('user', $user);

        return $message;
    }
}
