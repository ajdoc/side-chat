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
        $query = $server->channels();

        // A private channel is absent from the sidebar of anyone not allowed in it, rather
        // than present-but-locked: a locked door still tells you the room exists, and the
        // point of the feature is that it doesn't. Staff see everything, since they're the
        // ones who decide what's private. Done as a query rather than a filter on the page
        // so pagination counts what the viewer can actually see.
        if ($user !== null && ! $server->isStaff($user)) {
            $query->where(fn ($q) => $q
                ->where('is_private', false)
                ->orWhereHas('allowedMembers', fn ($m) => $m->whereKey($user->getKey())));
        }

        $channels = $query->paginate(self::PER_PAGE);

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
