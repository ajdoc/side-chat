<?php

namespace App\Actions\Channel;

use App\Events\ChannelAccessUpdated;
use App\Models\Channel;
use Illuminate\Support\Facades\DB;

final class UpdateChannelAccessAction
{
    /**
     * Set whether a channel is private and, if it is, who is allowed in.
     *
     * Ids that aren't members of the server are dropped rather than rejected: the roster is
     * the server's, so an id that isn't on it can only be stale (someone left while the
     * dialog was open) and failing the whole save over it would be unhelpful. Staff are not
     * added — they're allowed in by rule (see Channel::hasMember), and storing them would
     * leave a list that silently goes wrong the moment somebody is promoted or demoted.
     *
     * Going public clears the list instead of keeping it: a public channel's roster is the
     * server's, and a remembered one would be a second copy quietly drifting out of date.
     * The cost is that flipping private → public → private loses the selection, which is
     * the honest trade — the alternative is restoring people who left months ago.
     *
     * @param  array<int, int>  $memberIds
     */
    public function handle(Channel $channel, bool $isPrivate, array $memberIds): Channel
    {
        $allowed = $isPrivate
            ? $channel->server?->members()->whereKey($memberIds)->pluck('users.id')->all() ?? []
            : [];

        DB::transaction(function () use ($channel, $isPrivate, $allowed): void {
            $channel->update(['is_private' => $isPrivate]);
            $channel->allowedMembers()->sync($allowed);
        });

        broadcast(new ChannelAccessUpdated($channel));

        return $channel->load('allowedMembers');
    }
}
