<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per pair of people, not one per direction.
 *
 * A friendship is symmetric once it exists, and the moment you store it twice the two
 * halves can disagree: accepted here, still pending there. So the pair gets a single row,
 * and `pair_key` — the two ids sorted and joined, exactly like `conversations.dm_key` —
 * carries a unique index so the database, not a lookup-then-insert, is what stops Ana and
 * Ben ending up with two requests when they both press Add at the same moment.
 *
 * Direction still matters, and that's what `user_id`/`friend_id` are for: while pending,
 * `user_id` is whoever asked, and only `friend_id` may accept. While blocked, `user_id` is
 * the blocker, and only they may undo it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friendships', function (Blueprint $table) {
            $table->id();
            // The requester (pending) or the blocker (blocked). Meaningless once accepted.
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'friend_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending | accepted | blocked
            $table->timestamps();

            $table->string('pair_key')->unique();
            // "Who are my friends / who is waiting on me" runs from either side of the pair.
            $table->index(['user_id', 'status']);
            $table->index(['friend_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friendships');
    }
};
