<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A map may name rectangles that show whatever the room is currently watching.
 *
 * Until now a shared screen lived only in the band of tiles over the room — the same place a
 * video call puts it. That is right for a meeting and wrong for a cinema: the point of building a
 * room with a screen at the end of it is that the screen is *in the room*, that you sit down
 * facing it, and that where you are standing is what decides whether you can see it.
 *
 * A list of placements rather than one screen per map, because a map can be a building: a foyer
 * with a trailer loop and two auditoria is three surfaces, and they all show the same source.
 *
 * ## Why this is not furniture
 *
 * A television *is* furniture and stays furniture — it is an object you face and press E on. This
 * is a **surface**, and the difference is that it belongs to the map's artwork rather than to its
 * contents: on a backdrop map the cinema screen is painted into the picture, at a position no
 * catalogue item could be expected to line up with. So it is geometry in the map document, like
 * a zone or a doorway, and it names an area rather than a thing standing in one.
 *
 * Nothing about it is *sent* — the video comes over the call exactly as it always did, and this
 * only decides where each browser paints it. See `drawScreens` in lib/spaceMapEngine.ts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('side_space_maps', function (Blueprint $table) {
            $table->json('screens')->nullable()->after('portals');
        });
    }

    public function down(): void
    {
        Schema::table('side_space_maps', function (Blueprint $table) {
            $table->dropColumn('screens');
        });
    }
};
