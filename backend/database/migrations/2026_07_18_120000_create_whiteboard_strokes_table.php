<?php

use App\Models\SideChat;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shared whiteboard is what makes a side chat a *workspace* rather than just a smaller
 * channel — a persistent, collaborative vector board that lives beside the timeline.
 *
 * There is no `whiteboards` table: a side chat's board simply *is* the set of strokes that
 * point at it. One stroke is one committed mark (a pen path, a shape, a text label, a
 * sticky note); the moving cursor and the in-progress drag never land here — those ride
 * over Reverb whispers and expire. Erase removes a stroke; clear removes them all.
 *
 * `client_id` is the drawer's own id for the stroke, so the optimistic copy it painted
 * locally can be reconciled with the one that comes back over the broadcast.
 *
 * A later migration generalises this to channels too — see
 * add_channel_to_whiteboard_strokes_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whiteboard_strokes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(SideChat::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            // pen | rect | ellipse | line | arrow | text | note
            $table->string('kind');
            // Geometry + style for this kind of mark (points, color, width, text, …).
            $table->json('payload');
            // The drawer's local id for this stroke, for optimistic reconciliation.
            $table->string('client_id');
            $table->timestamps();

            $table->index('side_chat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whiteboard_strokes');
    }
};
