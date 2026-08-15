<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A map may name rectangles you can walk up to and look at — the frames of a gallery.
 *
 * Geometry only, and open to any member exactly as zones, doorways and screens are: drawing a
 * frame around a painting somebody drew is *building the room*. What goes **in** the frame is a
 * file and a wall label, which is a different kind of thing entirely and lives in its own table
 * — see the exhibits migration that follows this one.
 *
 * A rectangle rather than a point, because a painting is a shape on a wall and the frame has to
 * cover it: the client offers to open whatever you are standing in front of, and "in front of"
 * only means something if the thing has an extent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('side_space_maps', function (Blueprint $table) {
            $table->json('exhibits')->nullable()->after('screens');
        });
    }

    public function down(): void
    {
        Schema::table('side_space_maps', function (Blueprint $table) {
            $table->dropColumn('exhibits');
        });
    }
};
