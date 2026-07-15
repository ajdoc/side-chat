<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Server;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ChannelService
{
    public const PER_PAGE = 200;

    public function __construct(private readonly ReadReceiptService $reads) {}

    /**
     * A server's channels, each carrying the caller's unread count so the sidebar can
     * badge them. Counted in one grouped query for the whole page, not per channel.
     */
    public function forServer(Server $server, ?User $user = null): LengthAwarePaginator
    {
        $channels = $server->channels()->paginate(self::PER_PAGE);

        if ($user === null) {
            return $channels;
        }

        $counts = $this->reads->unreadCounts($user, $channels->pluck('id')->all());

        $channels->getCollection()->each(
            fn (Channel $channel) => $channel->unread_count = (int) ($counts[$channel->id] ?? 0)
        );

        return $channels;
    }
}
