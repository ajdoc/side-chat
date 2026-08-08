<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\ChannelRead;
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
        // Containers only. Discussions come back nested underneath (see below) rather than as
        // siblings — paginating a flat list of both would let one talkative channel's discussions
        // push another channel off the page entirely.
        $query = $server->channels()->whereNull('parent_id');

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

        // The same visibility filter applied to the nested discussions, so a private discussion
        // inside a public channel is absent from the branch for the same reason a private channel
        // is absent from the trunk.
        $query->with(['discussions' => function ($discussions) use ($server, $user) {
            if ($user !== null && ! $server->isStaff($user)) {
                $discussions->where(fn ($q) => $q
                    ->where('is_private', false)
                    ->orWhereHas('allowedMembers', fn ($m) => $m->whereKey($user->getKey())));
            }
        }]);

        $channels = $query->paginate(self::PER_PAGE);

        if ($user === null) {
            return $channels;
        }

        // Counted for the discussions, since they are what hold messages now. A container's own
        // count is the sum of its children's: the sidebar badges a collapsed channel with what is
        // waiting *inside* it, which is the only number that means anything once the timeline
        // moved down a level.
        $discussions = $channels->getCollection()->flatMap->discussions;
        $counts = $this->reads->unreadCounts($user, $discussions->pluck('id')->all());

        $discussions->each(
            fn (Channel $discussion) => $discussion->unread_count = (int) ($counts[$discussion->id] ?? 0)
        );

        $channels->getCollection()->each(
            fn (Channel $channel) => $channel->unread_count = (int) $channel->discussions->sum('unread_count')
        );

        // Which discussion each channel opens on *for this person*. One query for the page, and
        // absent for every channel they haven't chosen one in — which is nearly all of them.
        $defaults = ChannelRead::where('user_id', $user->getKey())
            ->whereIn('channel_id', $channels->pluck('id')->all())
            ->whereNotNull('default_child_id')
            ->pluck('default_child_id', 'channel_id');

        $channels->getCollection()->each(
            fn (Channel $channel) => $channel->default_child_id = $defaults[$channel->id] ?? null
        );

        return $channels;
    }
}
