<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Side Space stops being one room and becomes a building: an overworld plus its interiors.
 *
 * The table was one row per channel. That made every interior a separate *channel* — its own
 * voice room, its own entry in the sidebar, its own permissions — so walking through a door
 * meant tearing down the call and dialling back into another one. Fine for travelling between
 * places; wrong for a doorway, where the whole illusion is that you never left.
 *
 * So the unique key moves from `channel_id` to `(channel_id, slug)`. Every existing map becomes
 * its channel's `main`, which is the one a channel opens to and the only one that has to exist.
 * Nothing else about a map changes: an interior is a *whole map*, with its own grid, zones,
 * furniture and doors back out, and every rule that governs the main one governs it too.
 *
 * The slug rather than the id is what portals point at, because a map is authored and reasoned
 * about by name ("the lobby", "screen-one") and because duplicating a channel duplicates its
 * maps — see CreateDiscussionAction, where ids would all be new but the links between them have
 * to survive the copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('side_space_maps', function (Blueprint $table) {
            // Defaulted, so every map that already exists becomes its channel's main one and
            // every call site that creates a map without naming a slug keeps working.
            $table->string('slug', 40)->default('main');
        });

        Schema::table('side_space_maps', function (Blueprint $table) {
            $table->dropUnique('side_space_maps_channel_id_unique');
            $table->unique(['channel_id', 'slug']);
        });
    }

    public function down(): void
    {
        // Only the main map survives going back — the others are rooms this schema cannot hold.
        DB::table('side_space_maps')->where('slug', '!=', 'main')->delete();

        Schema::table('side_space_maps', function (Blueprint $table) {
            $table->dropUnique(['channel_id', 'slug']);
            $table->dropColumn('slug');
            $table->unique('channel_id');
        });
    }
};
