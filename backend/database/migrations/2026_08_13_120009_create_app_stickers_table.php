<?php

use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Sticker Wall — a shared collage a channel builds one sticker at a time.
 *
 * ## Why this isn't the whiteboard
 *
 * It very nearly is, and reusing the board was the first plan. What stopped it is that a
 * whiteboard stroke belongs to the *board*, while a sticker is an object: it's drawn somewhere
 * else, then placed, then moved, and it's the unit somebody deletes when they want their own
 * thing gone. Strokes can't be moved as a group or owned as a group without inventing exactly
 * this row on top of them.
 *
 * So a sticker is its own record, and its `content` is the drawing — the same stroke shape the
 * whiteboard already stores and the same renderer draws it, which is where the reuse actually
 * lands. Composition over a shared table.
 *
 * ## Placement
 *
 * `x`/`y` are wall coordinates and `z` is paint order, so a wall is deliberately allowed to
 * overlap — that's what makes a collage read as one. `z` is handed out ascending, so the newest
 * sticker lands on top; rearranging is a matter of rewriting it.
 *
 * `w`/`h` carry the drawn size so a sticker keeps its proportions wherever it's placed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_stickers', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Channel::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->string('name', 80)->nullable();
            // The drawing: a background shape plus strokes/text, in the shape the client's
            // editor writes and its renderer reads. Free-form for the same reason a widget's
            // state is — the shape belongs to the renderer, not to the API.
            $table->json('content');
            $table->integer('x')->default(0);
            $table->integer('y')->default(0);
            $table->integer('z')->default(0);
            $table->unsignedInteger('w')->default(160);
            $table->unsignedInteger('h')->default(160);
            $table->integer('rotation')->default(0);
            $table->timestamps();

            // The wall renders in paint order, bottom to top.
            $table->index(['channel_id', 'z']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_stickers');
    }
};
