<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Layers on the Board.
 *
 * A stroke gains a layer index, and the board draws them in order. That's the whole feature at
 * the storage level — layers are a *rendering* and *selection* idea, so what the schema owes
 * them is one sortable number per stroke and nothing else.
 *
 * Defaulting to 0 is what makes this safe on an existing board: every stroke ever drawn is on
 * layer 0, which is exactly the single-layer board people already have. Nothing needs
 * backfilling and nothing moves.
 *
 * The layers' own names and visibility live on the surface rather than here — a name repeated
 * across ten thousand strokes is ten thousand copies of one fact. They go in the surface's
 * existing JSON settings; see the board's own storage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whiteboard_strokes', function (Blueprint $table) {
            $table->unsignedSmallInteger('layer')->default(0)->after('kind');

            // The board's only read: this surface's strokes, in paint order. Layer leads,
            // because that is now what paint order means.
            $table->index(['channel_id', 'layer']);
            $table->index(['side_chat_id', 'layer']);
        });
    }

    public function down(): void
    {
        Schema::table('whiteboard_strokes', function (Blueprint $table) {
            $table->dropIndex(['channel_id', 'layer']);
            $table->dropIndex(['side_chat_id', 'layer']);
            $table->dropColumn('layer');
        });
    }
};
