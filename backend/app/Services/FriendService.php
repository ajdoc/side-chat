<?php

namespace App\Services;

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads over the friendships table.
 *
 * Every method here takes the viewer, because the table stores one row per pair and the
 * row says nothing about which of the two is asking. "Pending" is one status and two very
 * different screens: a request you can accept, and a request you can only cancel.
 */
class FriendService
{
    /** @return Collection<int, Friendship> */
    public function friendshipsFor(User $user, string $status): Collection
    {
        return Friendship::query()
            ->involving($user->getKey())
            ->where('status', $status)
            ->with(['user', 'friend'])
            ->latest('updated_at')
            ->get();
    }

    /** The people this user is friends with. */
    public function friendsOf(User $user): Collection
    {
        $ids = $this->friendIds($user);

        return User::whereKey($ids)->orderBy('name')->get();
    }

    /**
     * Ids only — for the reachability checks, which want a set and not a hydrate.
     *
     * @return array<int, int>
     */
    public function friendIds(User $user): array
    {
        return Friendship::query()
            ->involving($user->getKey())
            ->where('status', Friendship::ACCEPTED)
            ->get(['user_id', 'friend_id'])
            ->map(fn (Friendship $f) => $f->otherId($user->getKey()))
            ->all();
    }

    public function areFriends(User $user, User $other): bool
    {
        $friendship = Friendship::between($user->getKey(), $other->getKey());

        return $friendship?->isAccepted() ?? false;
    }

    /**
     * Whether either of these two has blocked the other.
     *
     * Deliberately symmetric: a block is a wall, and a wall you can talk through from one
     * side isn't one. Which of them put it up only matters for taking it down again.
     */
    public function isBlockedEitherWay(User $user, User $other): bool
    {
        $friendship = Friendship::between($user->getKey(), $other->getKey());

        return $friendship?->isBlocked() ?? false;
    }

    /**
     * Everyone this user can't reach and can't be reached by — both directions in one set,
     * because a contact list has no use for the difference.
     *
     * @return array<int, int>
     */
    public function blockedIdsEitherWay(User $user): array
    {
        return Friendship::query()
            ->involving($user->getKey())
            ->where('status', Friendship::BLOCKED)
            ->get(['user_id', 'friend_id'])
            ->map(fn (Friendship $f) => $f->otherId($user->getKey()))
            ->all();
    }

    /**
     * People this user has blocked — the only list where direction is the whole point.
     *
     * @return Collection<int, Friendship>
     */
    public function blockedBy(User $user): Collection
    {
        return Friendship::query()
            ->where('user_id', $user->getKey())
            ->where('status', Friendship::BLOCKED)
            ->with('friend')
            ->latest('updated_at')
            ->get();
    }
}
