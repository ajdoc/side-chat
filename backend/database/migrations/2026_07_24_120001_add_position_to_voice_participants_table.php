<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where somebody is standing in a Side Space.
 *
 * On `voice_participants` rather than a table of its own because a seat in a Side Space *is* a
 * seat in its call — you walk in, you're in the room, and the row that already records that is
 * this one. Null everywhere else, which is every voice channel and every DM.
 *
 * This is not the live position: that's whispered peer-to-peer many times a second and never
 * comes near the database (see useSpacePresence). This is the *remembered* one, written on a
 * long throttle, so that reloading the page puts you back where you were standing instead of
 * teleporting you to spawn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_participants', function (Blueprint $table) {
            $table->integer('x')->nullable();
            $table->integer('y')->nullable();
            $table->string('facing', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('voice_participants', function (Blueprint $table) {
            $table->dropColumn(['x', 'y', 'facing']);
        });
    }
};
