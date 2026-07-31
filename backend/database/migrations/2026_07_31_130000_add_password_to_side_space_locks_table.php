<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A password on a locked door.
 *
 * Until now a lock was a list of people: you were on it or you weren't, and if you weren't there
 * was nothing you could do about it from the door itself. A password is the other half of what a
 * lock is for — a room you can let somebody into without knowing in advance that it would be
 * them, which is how a club, a study room, or a "the code is in the pinned message" door works.
 *
 * ## Two columns, not one
 *
 * `password` is the hash of the phrase, and it is *never* sent to a browser. Only the fact that
 * one is set travels with the map, because the door has to be able to say "this one will open if
 * you know the words" rather than looking like a door that will never open for you.
 *
 * `passed` is who has already said them. Keeping it means the password is asked for once rather
 * than at every crossing — a door that re-prompted each time you walked through it would be a
 * door people prop open. It is deliberately separate from `allowed`, the keys the room's owner
 * handed out: those two are different facts with different authors, and folding a password-entry
 * into the granted list would quietly make it look like the owner had given somebody a key. It
 * also means clearing or changing the password can empty `passed` on its own, which is the whole
 * point of changing a password.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('side_space_locks', function (Blueprint $table) {
            // The bcrypt hash. Null is the ordinary case: most locks are a list of people.
            $table->string('password')->nullable()->after('allowed');
            // User ids who have entered it. Cleared whenever the password itself changes.
            $table->json('passed')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('side_space_locks', function (Blueprint $table) {
            $table->dropColumn(['password', 'passed']);
        });
    }
};
