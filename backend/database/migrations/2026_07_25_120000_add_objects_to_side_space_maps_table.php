<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Furniture: the layer that turns a floor plan into a room.
 *
 * A second list rather than more tile characters, because a decoration is not ground — it has a
 * footprint bigger than one square, it may or may not block movement, and some of them *do*
 * something when you walk up and press E. None of that fits in a single character, and pretending
 * it did would mean a tile alphabet that grew by one letter per piece of furniture ever added.
 *
 * Each entry is `{"id": "d-k3f", "kind": "speaker", "x": 12, "y": 5}` and nothing else. Size,
 * solidity and what it opens are looked up by kind in {@see \App\Support\SideSpace\Decorations}
 * — see there for why the client isn't allowed to send them.
 *
 * Existing rooms get an empty list, which is exactly what they have today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('side_space_maps', function (Blueprint $table) {
            $table->json('objects')->default('[]')->after('zones');
        });
    }

    public function down(): void
    {
        Schema::table('side_space_maps', function (Blueprint $table) {
            $table->dropColumn('objects');
        });
    }
};
