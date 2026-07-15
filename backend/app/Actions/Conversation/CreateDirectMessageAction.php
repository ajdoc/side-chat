<?php

namespace App\Actions\Conversation;

use App\Events\ConversationCreated;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class CreateDirectMessageAction
{
    /**
     * Open the DM between two people — or hand back the one that already exists.
     *
     * "Open a DM" is a button people press without much thought, and pressing it twice
     * must not produce two of them: two histories, two unread counts, and a coin toss over
     * which one the next message lands in. The obvious guard — look it up, and create it if
     * it isn't there — loses that race the very first time two people message each other at
     * the same moment, and it loses it silently.
     *
     * So the pair's identity is a column (`dm_key`) with a unique index on it, and the
     * database is the one that decides. We try to insert; if the index says someone beat us
     * to it by a millisecond, that isn't an error, it's the answer — go and read theirs.
     *
     * Idempotent, therefore: whoever calls this, however many times, ends up looking at the
     * same conversation.
     */
    public function handle(User $user, User $other): Conversation
    {
        $key = Conversation::dmKey($user->id, $other->id);

        $existing = Conversation::where('dm_key', $key)->first();
        if ($existing) {
            return $existing->load('members', 'channel');
        }

        try {
            $conversation = DB::transaction(function () use ($key, $user, $other): Conversation {
                $conversation = Conversation::create(['type' => 'dm', 'dm_key' => $key]);

                // A DM with yourself is your own notes — one member, not two of the same.
                $conversation->members()->attach(array_unique([$user->id, $other->id]));

                // The channel *is* the conversation, as far as every message, thread,
                // reaction, pin and call in the app is concerned. Nothing reads this name.
                $conversation->channel()->create(['name' => 'direct', 'type' => 'text']);

                return $conversation;
            });
        } catch (QueryException $e) {
            // Lost the race on the unique index. Theirs is as good as ours.
            $conversation = Conversation::where('dm_key', $key)->first();

            if ($conversation === null) {
                throw $e; // a genuine failure, not a collision
            }

            return $conversation->load('members', 'channel');
        }

        $conversation->load('members', 'channel');

        // The other person isn't subscribed to a conversation they've never heard of, so
        // this goes to them personally — see the `user.{id}` stream in routes/channels.php.
        broadcast(new ConversationCreated($conversation));

        return $conversation;
    }
}
