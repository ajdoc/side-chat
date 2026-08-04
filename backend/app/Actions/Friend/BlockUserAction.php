<?php

namespace App\Actions\Friend;

use App\Events\FriendshipRemoved;
use App\Events\FriendshipUpdated;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class BlockUserAction
{
    /**
     * Put a wall up between you and someone else.
     *
     * Blocking reuses the friendship row rather than getting a table of its own, because
     * the two states are mutually exclusive by definition and one row per pair is what
     * makes "what is the state between these two people" a single lookup — which is the
     * question the DM guard asks on every message.
     *
     * Blocking a friend unfriends them, necessarily: the row can only say one thing. And
     * `user_id` is rewritten to the blocker, because from here on that's the only
     * direction that matters — they're the only one who can take it down.
     */
    public function handle(User $user, User $other): Friendship
    {
        if ($user->id === $other->id) {
            throw ValidationException::withMessages([
                'user_id' => 'You cannot block yourself.',
            ]);
        }

        $friendship = Friendship::between($user->id, $other->id);

        if ($friendship === null) {
            $friendship = Friendship::create([
                'user_id' => $user->id,
                'friend_id' => $other->id,
                'status' => Friendship::BLOCKED,
                'pair_key' => Friendship::pairKey($user->id, $other->id),
            ]);
        } else {
            // Already blocked *by them*? Then this isn't ours to overwrite — silently
            // flipping the direction would hand the blocked person the unblock button.
            if ($friendship->isBlocked() && $friendship->user_id !== $user->id) {
                throw new AccessDeniedHttpException('You cannot act on this person.');
            }

            $friendship->update([
                'user_id' => $user->id,
                'friend_id' => $other->id,
                'status' => Friendship::BLOCKED,
            ]);
        }

        broadcast(new FriendshipUpdated($friendship));

        return $friendship;
    }

    /** Take the wall down. The blocker only — see above. Leaves the two as strangers. */
    public function unblock(User $user, User $other): void
    {
        $friendship = Friendship::between($user->id, $other->id);

        if ($friendship === null || ! $friendship->isBlocked()) {
            return;
        }

        if ($friendship->user_id !== $user->id) {
            throw new AccessDeniedHttpException('You did not block this person.');
        }

        $event = FriendshipRemoved::of($friendship);
        $friendship->delete();
        broadcast($event);
    }
}
