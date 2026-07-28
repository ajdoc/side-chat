<?php

namespace App\Http\Requests\SideChatForum;

use App\Http\Requests\MemberRequest;
use App\Models\Channel;
use App\Models\SideChatForum;

/**
 * Creating, renaming, reordering or deleting a channel's forums — the staff's (see
 * {@link SideChatForum::canManageIn}).
 *
 * Extends MemberRequest rather than ServerStaffRequest so the *channel's* access list is
 * checked first: a server admin who isn't on a private channel's allow-list has no business
 * rearranging the list of a channel they can't see. `parent::authorize()` is that check.
 */
abstract class ManageSideChatForumRequest extends MemberRequest
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        $channel = $this->forumChannel();
        $user = $this->user();

        return $channel !== null && $user !== null && SideChatForum::canManageIn($channel, $user);
    }

    /**
     * The channel being rearranged — bound directly when creating or reordering, reached
     * through the forum when renaming or deleting one.
     */
    protected function forumChannel(): ?Channel
    {
        $channel = $this->route('channel');
        if ($channel instanceof Channel) {
            return $channel;
        }

        $forum = $this->route('forum');

        return $forum instanceof SideChatForum ? $forum->loadMissing('channel')->channel : null;
    }
}
