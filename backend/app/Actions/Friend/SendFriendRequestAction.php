<?php

namespace App\Actions\Friend;

use App\Events\FriendshipUpdated;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

final class SendFriendRequestAction
{
    /**
     * Ask someone to be friends — or answer the request they already sent you.
     *
     * The second half of that sentence is the whole reason this isn't a `create()`. Two
     * people adding each other within the same minute is the ordinary case, not the edge
     * one, and the wrong outcome there is two rows staring at each other, both pending,
     * neither acceptable. So a request that meets one coming the other way is an accept.
     *
     * Everything else the pair might already have is a no: an accepted row means you're
     * asking a friend to be your friend, and a blocked row means one of you said no
     * loudly. Neither is worth an exception the caller has to distinguish — the request
     * class checks first, and this is the race-loser's path.
     */
    public function handle(User $user, User $other): Friendship
    {
        if ($user->id === $other->id) {
            throw ValidationException::withMessages([
                'user_id' => 'You are already your own best friend.',
            ]);
        }

        $key = Friendship::pairKey($user->id, $other->id);

        if ($existing = Friendship::where('pair_key', $key)->first()) {
            return $this->resolveExisting($user, $existing);
        }

        try {
            $friendship = Friendship::create([
                'user_id' => $user->id,
                'friend_id' => $other->id,
                'status' => Friendship::PENDING,
                'pair_key' => $key,
            ]);
        } catch (QueryException $e) {
            // They pressed Add at the same moment we did. Theirs is as good as ours — and
            // now that both sides have asked, the answer is yes.
            $existing = Friendship::where('pair_key', $key)->first();

            if ($existing === null) {
                throw $e; // a genuine failure, not a collision
            }

            return $this->resolveExisting($user, $existing);
        }

        broadcast(new FriendshipUpdated($friendship));

        return $friendship;
    }

    /** A row was already there: accept it if it was aimed at us, otherwise refuse. */
    private function resolveExisting(User $user, Friendship $existing): Friendship
    {
        if ($existing->isBlocked()) {
            throw ValidationException::withMessages([
                'user_id' => 'You cannot send this person a friend request.',
            ]);
        }

        if ($existing->isAccepted()) {
            return $existing;
        }

        if ($existing->isIncomingFor($user->id)) {
            $existing->update(['status' => Friendship::ACCEPTED]);
            broadcast(new FriendshipUpdated($existing));

            return $existing;
        }

        // Our own request, sent twice. Pressing Add again is not an error.
        return $existing;
    }
}
