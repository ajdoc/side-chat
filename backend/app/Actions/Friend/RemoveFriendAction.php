<?php

namespace App\Actions\Friend;

use App\Events\FriendshipRemoved;
use App\Models\Friendship;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class RemoveFriendAction
{
    /**
     * Unfriend someone, or take back a request you sent.
     *
     * The same row and the same delete for both, which is why they're one action: from the
     * table's point of view "we're not friends any more" and "never mind" are identical,
     * and only the button that was pressed differs.
     *
     * A block is *not* removed here. Deleting it is unblocking, which is a decision only
     * the blocker gets to make and has its own endpoint — otherwise anyone could clear the
     * wall they were put behind by pressing Remove friend.
     */
    public function handle(User $user, Friendship $friendship): void
    {
        if (! in_array($user->id, [$friendship->user_id, $friendship->friend_id], true)) {
            throw new AccessDeniedHttpException('This friendship is not yours.');
        }

        if ($friendship->isBlocked()) {
            throw new AccessDeniedHttpException('Unblock this person instead.');
        }

        $event = FriendshipRemoved::of($friendship);
        $friendship->delete();
        broadcast($event);
    }

    /** The same, addressed by person rather than by row — what the profile button has. */
    public function handleBetween(User $user, User $other): void
    {
        $friendship = Friendship::between($user->id, $other->id);

        if ($friendship !== null) {
            $this->handle($user, $friendship);
        }
    }
}
