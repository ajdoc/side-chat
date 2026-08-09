<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Tell me about this place" — one channel, one person.
 *
 * It lands on `channel_reads` for the same reason `default_child_id` did: that table is
 * already exactly the user-by-channel row this needs, and a table of its own would be the
 * same unique key written a second time. A DM needs no special case either, since a
 * conversation's messages live in a channel like everything else.
 *
 * Null means "no opinion" and inherits — from the parent channel if this is a discussion,
 * then from the user's default for this kind of room. Null is therefore not the same as
 * `all`, and the difference is load-bearing: changing your default has to move every place
 * you never explicitly set, and only those.
 *
 * `muted_until` is separate from the level rather than a fourth level, because it is
 * temporary by nature and must not destroy the preference it suspends — "quiet for an
 * hour" has to leave "and then go back to how it was" written down somewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_reads', function (Blueprint $table) {
            $table->string('notify_level', 16)->nullable();
            $table->timestamp('muted_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('channel_reads', function (Blueprint $table) {
            $table->dropColumn(['notify_level', 'muted_until']);
        });
    }
};
