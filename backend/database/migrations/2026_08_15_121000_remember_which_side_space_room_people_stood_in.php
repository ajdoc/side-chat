<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A remembered position gains the room it was in.
 *
 * `x` and `y` alone were enough while a Side Space was one map. Now that it is a building, a
 * pair of coordinates is an answer to the wrong question: (12, 4) in the lobby and (12, 4) in
 * screen one are different places, and a reload that put people back at their coordinates would
 * put half of them through a wall in a room they were never in.
 *
 * A slug rather than a foreign key to `side_space_maps`, to match how portals point at maps —
 * and so that a deleted interior leaves a stale slug that {@see \App\Models\SideSpaceMap::spawnPoint}
 * quietly corrects at the moment of use, exactly as it already corrects a position that has had
 * a wall painted over it, rather than a cascade that would silently move people.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_participants', function (Blueprint $table) {
            // Nullable, not defaulted to `main`: null means "no remembered room", which is what
            // every row written before this migration honestly is. The read side treats null and
            // `main` the same, so nobody is moved by this running.
            $table->string('space_map', 40)->nullable()->after('facing');
        });
    }

    public function down(): void
    {
        Schema::table('voice_participants', function (Blueprint $table) {
            $table->dropColumn('space_map');
        });
    }
};
