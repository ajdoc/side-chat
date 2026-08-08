<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A map may name a whole-map image to draw instead of its tile art — see {@see Backdrops}.
 *
 * Nullable, and null on every room that already exists, which is what they are: a room built out
 * of tiles draws its tiles. The tile grid stays on backdrop maps too and keeps doing the only job
 * it ever really did, which is deciding where people can walk.
 *
 * A key rather than a URL, and the reason is in the class docblock: any member may save a map, so
 * a stored URL is an arbitrary address a member gets every other browser in the room to fetch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('side_space_maps', function (Blueprint $table) {
            $table->string('backdrop', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('side_space_maps', function (Blueprint $table) {
            $table->dropColumn('backdrop');
        });
    }
};
