<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A room can be somebody's, or it can be several people's.
 *
 * The table was built one-row-per-room, with the owner as a nullable column on it and a unique
 * key on `(map, zone)`. That shape can only ever hold one name, and a room with two people
 * responsible for it is the ordinary case rather than the exception — a meeting room belongs to
 * the team that uses it.
 *
 * So the row stops being "this room, and who owns it" and becomes "this person owns this room".
 * Un-assigning is deleting a row rather than nulling a column, which is also why `owner_id` can
 * stop being nullable: a row with no owner in it was only ever a way of spelling "no row".
 *
 * Written as an alter rather than by editing the original migration, because the original has
 * already run. Rolling forward is always safe; asking somebody to roll back and lose the rooms
 * they had already set up is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('side_space_rooms', function (Blueprint $table) {
            // One room, several owners — so the pair that has to be unique now includes who.
            $table->dropUnique(['side_space_map_id', 'zone_id']);
        });

        // A row that never named anybody was the old way of saying the room was unowned. There
        // is no such row in the new shape, so they go before the column stops allowing it.
        \DB::table('side_space_rooms')->whereNull('owner_id')->delete();

        Schema::table('side_space_rooms', function (Blueprint $table) {
            $table->unique(['side_space_map_id', 'zone_id', 'owner_id']);
            // Kept as the lookup the door rules lean on: "who is in charge of this room" is asked
            // for every lock read, and it is now a list rather than a value.
            $table->index(['side_space_map_id', 'zone_id']);
        });
    }

    public function down(): void
    {
        Schema::table('side_space_rooms', function (Blueprint $table) {
            $table->dropUnique(['side_space_map_id', 'zone_id', 'owner_id']);
            $table->dropIndex(['side_space_map_id', 'zone_id']);
            $table->unique(['side_space_map_id', 'zone_id']);
        });
    }
};
