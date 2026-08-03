<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A server may have many rules of the same built-in kind.
 *
 * The automations table shipped with `unique(server_id, builtin)`, written when the only
 * built-in was the welcome message — which genuinely is one per server, because "greet
 * people when they join" is a setting rather than a list.
 *
 * Reaction roles are the counter-example: a server wants 🎮 → Griefer *and* 🎨 → Artist
 * *and* 🎵 → DJ, and each pair is its own rule (its own message, its own emoji, its own
 * badge). The unique index would have allowed exactly one.
 *
 * Singleton-ness moves into the code that owns each feature instead — the welcome message is
 * a single row because BotDashboardController looks one up and rewrites it, not because the
 * schema forbids a second. That's the right place for it: it's a fact about what the welcome
 * message *means*, not about what an automation is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'builtin']);
            // Still indexed — the feature pages all read "this server's rules of this kind".
            $table->index(['server_id', 'builtin']);
        });
    }

    public function down(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            $table->dropIndex(['server_id', 'builtin']);
            $table->unique(['server_id', 'builtin']);
        });
    }
};
