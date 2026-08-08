<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A map's backdrop becomes a *list of placements* rather than one image stretched over everything.
 *
 * The original column said "this whole map is drawn as this picture", which was true of the map it
 * was built for and false of the thing people immediately want to do with it: keep the room they
 * have already decorated, extend the grid to the right, and put the city in the new space. A
 * single whole-map key cannot express that — it can only claim the office is also New York.
 *
 * So a backdrop is now a picture *and where it goes*: `[{"key": "gather-town", "x": 30, "y": 0,
 * "w": 64, "h": 35}]`. Tiles outside every placement are drawn as tile art exactly as they always
 * were, which is what lets one map be half hand-built room and half artwork.
 *
 * Existing rows carry over as a single placement covering the whole grid, which is precisely what
 * they meant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('side_space_maps', function (Blueprint $table) {
            $table->json('backdrops')->nullable();
        });

        // The old meaning, written in the new shape: one picture over the entire grid.
        DB::table('side_space_maps')
            ->whereNotNull('backdrop')
            ->orderBy('id')
            ->each(function ($map) {
                DB::table('side_space_maps')->where('id', $map->id)->update([
                    'backdrops' => json_encode([[
                        'key' => $map->backdrop,
                        'x' => 0,
                        'y' => 0,
                        'w' => $map->width,
                        'h' => $map->height,
                    ]]),
                ]);
            });

        Schema::table('side_space_maps', function (Blueprint $table) {
            $table->dropColumn('backdrop');
        });
    }

    public function down(): void
    {
        Schema::table('side_space_maps', function (Blueprint $table) {
            $table->string('backdrop', 40)->nullable();
        });

        // Only a placement that covers everything can be expressed in the old column; a map that
        // is part artwork loses the placement rather than pretending the whole grid is a city.
        DB::table('side_space_maps')
            ->whereNotNull('backdrops')
            ->orderBy('id')
            ->each(function ($map) {
                $first = json_decode($map->backdrops, true)[0] ?? null;
                $covers = $first && $first['x'] === 0 && $first['y'] === 0
                    && $first['w'] === $map->width && $first['h'] === $map->height;

                DB::table('side_space_maps')->where('id', $map->id)
                    ->update(['backdrop' => $covers ? $first['key'] : null]);
            });

        Schema::table('side_space_maps', function (Blueprint $table) {
            $table->dropColumn('backdrops');
        });
    }
};
