<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A board's layers, per surface.
 *
 * The strokes carry a `layer` index (see the whiteboard_strokes migration); this is the other
 * half — what each layer is *called* and whether it's shown. Kept here rather than on the
 * strokes for the obvious reason: a name repeated across ten thousand strokes is ten thousand
 * copies of one fact, and renaming a layer would be a mass update.
 *
 * The exact shape the client writes, so the array index *is* the layer number:
 *
 *     [{ "name": "Background", "visible": true }, { "name": "Sketch", "visible": true }]
 *
 * Per surface rather than per person, on the same reasoning as `desk_apps` beside it: a board is
 * one shared object, and a layer somebody hid for themselves would make "it's on the Sketch
 * layer" untrue for whoever they said it to. Visibility is a property of the board.
 *
 * NULL means "never used layers", which the client renders as the single unnamed layer 0 every
 * existing board already has. Storing a default would freeze it.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['channels', 'side_chats'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->json('board_layers')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['channels', 'side_chats'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('board_layers');
            });
        }
    }
};
