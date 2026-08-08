<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doorways: rectangles you walk into that put you somewhere else.
 *
 * A map has grown from a room you could see all at once into an island you walk across, and two
 * things follow from that which walking alone cannot solve. Crossing the whole city to reach the
 * park is a minute of holding a key, and a Side Space that is *several* rooms — one per discussion
 * — has no way to get from one to another except leaving and clicking a channel in a sidebar,
 * which is the opposite of being in a place.
 *
 * So a portal is stored like a zone is: a rectangle of tiles with a name, plus where it goes.
 * A region rather than a piece of furniture, because walking *into* it is the whole interaction —
 * there is nothing to press and nothing to look at, and a doorway you had to click would be a
 * button lying on the floor.
 *
 * The destination is one of two shapes:
 *
 *   - `{"kind": "point", "x": 40, "y": 12}` — somewhere else on this same map. Fast travel across
 *     a big island, and the cheap case: nothing loads, you are simply moved.
 *   - `{"kind": "room", "channel_id": 12, "x": 4, "y": 9}` — another Side Space entirely. The
 *     browser navigates, and `x`/`y` say where you arrive; without them you arrive at that room's
 *     own entrance.
 *
 * Kept in the map document rather than in their own table because they are *geometry* — the same
 * thing zones are. Every client already receives the whole map to draw and collide against, and a
 * portal is no more secret or more relational than a wall is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('side_space_maps', function (Blueprint $table) {
            $table->json('portals')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('side_space_maps', function (Blueprint $table) {
            $table->dropColumn('portals');
        });
    }
};
