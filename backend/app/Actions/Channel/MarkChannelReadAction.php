<?php

namespace App\Actions\Channel;

use App\Events\ChannelReadUpdated;
use App\Models\Channel;
use App\Models\ChannelRead;
use App\Models\User;
use App\Services\ReadReceiptService;

final class MarkChannelReadAction
{
    public function __construct(private readonly ReadReceiptService $reads) {}

    /**
     * Advance the caller's read marker and tell the channel, so everyone else's
     * seen-by row updates live. A no-op read (already further along, or a message id
     * that isn't in this channel) broadcasts nothing.
     */
    public function handle(Channel $channel, User $user, ?int $messageId = null): ?ChannelRead
    {
        $before = $channel->reads()->where('user_id', $user->id)->value('last_read_message_id');

        $read = $this->reads->markRead($channel, $user, $messageId);

        if ($read === null) {
            return null;
        }

        if ((int) $read->last_read_message_id !== (int) $before) {
            broadcast(new ChannelReadUpdated($read));
        }

        return $read;
    }
}
