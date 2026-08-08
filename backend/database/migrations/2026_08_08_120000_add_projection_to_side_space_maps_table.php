<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which way a Side Space is drawn: straight down at the grid, or turned 45° and squashed into the
 * isometric view most pixel-art rooms are drawn in.
 *
 * A column rather than a key inside the map document, because it's the one field about a map that
 * something other than the renderer might reasonably want to filter or count on, and because a
 * document key would have to be defaulted in three places (the model, the request, the client)
 * where a column defaults itself in one.
 *
 * `flat` for every room that already exists, which is not a compromise — it's what they are. The
 * whole point of keeping both projections is that a map authored against a top-down view stays
 * the room its author built.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('side_space_maps', function (Blueprint $table) {
            $table->string('projection', 16)->default('flat');
        });
    }

    public function down(): void
    {
        Schema::table('side_space_maps', function (Blueprint $table) {
            $table->dropColumn('projection');
        });
    }
};
