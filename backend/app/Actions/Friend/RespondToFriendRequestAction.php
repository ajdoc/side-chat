<?php

namespace App\Actions\Friend;

use App\Events\FriendshipRemoved;
use App\Events\FriendshipUpdated;
use App\Models\Friendship;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class RespondToFriendRequestAction
{
    /**
     * Accept a request, or turn it down.
     *
     * Only the person who was asked may do either — accepting your own request would make
     * "add friend" a unilateral act, which is the one thing a friend list must not be.
     * Declining deletes the row rather than recording a refusal: a "declined" status would
     * be a permanent record of a small rejection, and it would also block the far more
     * likely follow-up of asking again later.
     */
    public function handle(User $user, Friendship $friendship, bool $accept): ?Friendship
    {
        if (! $friendship->isIncomingFor($user->id)) {
            throw new AccessDeniedHttpException('This request is not yours to answer.');
        }

        if (! $accept) {
            $event = FriendshipRemoved::of($friendship);
            $friendship->delete();
            broadcast($event);

            return null;
        }

        $friendship->update(['status' => Friendship::ACCEPTED]);
        broadcast(new FriendshipUpdated($friendship));

        return $friendship;
    }
}
