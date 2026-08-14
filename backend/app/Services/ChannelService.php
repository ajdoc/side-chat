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

            // Which app each discussion is, for the app channels among them. Eager-loaded here
            // rather than fetched when one is opened, because the sidebar itself needs it: an
            // app channel's row carries its app's icon, so the answer has to arrive with the
            // tree rather than one request after it.
            $discussions->with('app');
        }]);

        // And on the containers, for the same reason — a container is drawn with the icon of
        // what it holds.
        $query->with('app');

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

        // Which discussion each channel opens on *for this person*, and how loud each place is
        // allowed to be. Both live on the same user-by-channel row, so they come back together
        // — and the notify half covers the discussions too, since a muted discussion inside an
        // unmuted channel still has to draw itself as muted.
        $ids = $channels->pluck('id')->merge($discussions->pluck('id'))->all();

        $prefs = ChannelRead::where('user_id', $user->getKey())
            ->whereIn('channel_id', $ids)
            ->get(['channel_id', 'default_child_id', 'notify_level', 'muted_until'])
            ->keyBy('channel_id');

        $channels->getCollection()->concat($discussions)->each(function (Channel $channel) use ($prefs) {
            $pref = $prefs->get($channel->id);

            $channel->default_child_id = $pref?->default_child_id;
            $channel->notify_level = $pref?->notify_level;
            $channel->muted_until = $pref?->muted_until;
        });

        return $channels;
    }
}
