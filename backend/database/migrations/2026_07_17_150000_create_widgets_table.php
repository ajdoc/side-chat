<?php

use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widgets: interactive, shared "apps" that live in a channel and are driven by chat
 * commands (`m!p …`, `k!add …`). A widget is a small state machine — a music player's
 * queue and transport, a kanban board's columns and cards — that everyone in the channel
 * sees the same view of, kept live over the same Reverb stream messages ride on.
 *
 * One widget per (channel, type): a channel has *the* player and *the* board, created
 * lazily the first time someone runs a command for it. Its state is a single JSON blob so
 * a new widget kind never needs a migration — only a handler (see App\Services\Widgets).
 * It surfaces in the timeline as a `type=widget` message carrying `widget_id`, exactly the
 * way a side chat's card hangs off the message it was spun from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Channel::class)->constrained()->cascadeOnDelete();
            // 'music' | 'kanban'. A string, not an enum — new kinds ship without a migration.
            $table->string('type', 20);
            // Whoever first ran a command for it. Kept as the "started by" even if they leave.
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            // The whole widget state — queue/transport, columns/cards, etc. Shape is the
            // handler's business; the table stays kind-agnostic.
            $table->json('state');
            $table->timestamps();

            // The lazy-create lookup ("does this channel already have a player?") and the
            // guarantee there's only ever one of each kind per channel.
            $table->unique(['channel_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widgets');
    }
};
