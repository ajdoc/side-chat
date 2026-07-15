<?php

namespace App\Services;

use App\Models\Channel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class PinService
{
    public const PER_PAGE = 50;

    /**
     * The channel's pinned messages — the Info > Pinned tab.
     *
     * Includes pins made inside threads (a thread reply carries its channel_id), because
     * "the important messages in this channel" doesn't stop being true at a thread
     * boundary. The rows carry `thread_id`, and the UI marks those the same way the Links
     * tab does — the jump only works on the main timeline.
     *
     * Ordered by when it was *pinned*, not when it was written: the newest pin is the one
     * someone just decided the channel needed to see.
     */
    public function forChannel(Channel $channel): LengthAwarePaginator
    {
        return $channel->messages()
            ->pinned()
            ->with(['user', 'replyTo.user', 'attachments', 'reactions.user', 'linkPreviews', 'pinner'])
            ->orderByDesc('pinned_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);
    }
}
