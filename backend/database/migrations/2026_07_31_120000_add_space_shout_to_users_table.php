<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A short line you leave hanging over your head in a Side Space.
 *
 * On the user rather than on a membership, for the same reason the avatar is: it's a thing you
 * wear, so it follows you from room to room and server to server. Null is the whole of "turn it
 * off" — there is no separate flag, because a bubble with nothing in it is not a bubble.
 *
 * Short on purpose. It is drawn over a tile-and-a-bit of a canvas at whatever zoom the room
 * happens to be at, so anything longer than a couple of words stops being readable and starts
 * being a banner across somebody else's room.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('space_shout', 40)->nullable()->after('space_pet');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('space_shout');
        });
    }
};
