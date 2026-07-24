<?php

use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Side Space channel's map — the room itself.
 *
 * One row per channel (hence the unique key), the same one-to-one shape a channel's note has.
 * It lives in its own table rather than as a column on `channels` because it's the only part of
 * a channel that is bulk geometry, and because every query that touches the sidebar reads
 * `channels` — there is no reason to drag a grid through them.
 *
 * `tiles` is an array of `height` strings of `width` characters: `.` floor, `#` wall, ` ` void.
 * A grid of characters rather than a grid of objects, because that is what a map *is* when you
 * strip it down — it stores small, diffs readably, validates in two lines, and is the exact
 * shape the editor paints into. `zones` are named rectangles (a meeting room, a table) which
 * the proximity rules treat as sealed; `spawn` is where somebody with no remembered position
 * walks in. See {@see \App\Models\SideSpaceMap}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('side_space_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Channel::class)->unique()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->json('tiles');
            $table->json('zones');
            $table->json('spawn');
            // Who last saved the map — shown in the editor, and null once they leave.
            $table->foreignIdFor(User::class, 'updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('side_space_maps');
    }
};
