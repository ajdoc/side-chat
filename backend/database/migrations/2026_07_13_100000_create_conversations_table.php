<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('dm'); // dm | group
            $table->string('name')->nullable(); // groups only — a DM is named after whoever you're talking to
            $table->foreignIdFor(User::class, 'owner_id')->nullable()->constrained('users')->nullOnDelete();

            /**
             * Canonical "who is in this DM", as "<lower id>:<higher id>".
             *
             * Two people must never end up with two DMs between them — that's two histories,
             * two unread counts, and a coin toss over which one a message lands in. Doing it
             * with a select-then-insert loses that race the first time both people click at
             * the same moment, so it's a unique index instead and the database settles it.
             *
             * Null for groups, which have no such uniqueness (you can have any number of
             * groups with exactly the same people in them).
             */
            $table->string('dm_key')->nullable()->unique();

            /**
             * The call, if one is happening. Unlike a server's voice channel — a room that
             * exists whether or not anyone is in it — a call in a chat is an *event*: it
             * starts when someone rings, and it is over when the last person hangs up.
             *
             * `started_at` is what makes the ring, the "join call" banner, and the duration
             * in "Call ended · 4m 12s" possible. `answered_at` is what tells a missed call
             * apart from a short one.
             */
            $table->timestamp('call_started_at')->nullable();
            $table->timestamp('call_answered_at')->nullable();
            $table->foreignIdFor(User::class, 'call_started_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
