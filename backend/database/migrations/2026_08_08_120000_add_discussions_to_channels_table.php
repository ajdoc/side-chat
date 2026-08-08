<?php

use App\Models\Channel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Channels gain children: a channel is now a container of discussions, and a discussion is
 * itself a channel with `parent_id` set. See DISCUSSIONS.md for why a discussion reuses the
 * channels table rather than getting a model of its own.
 *
 * This migration only opens the columns. The backfill that gives every existing channel its
 * "General" child — and moves that channel's messages, map and desk onto it — is the
 * migration immediately after this one, kept separate because one is reversible schema and
 * the other is a one-way move of live rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            // Cascade, not null-on-delete: a discussion cannot outlive the channel it is a
            // discussion *of*, and orphaning it would leave a timeline nothing links to.
            $table->foreignIdFor(Channel::class, 'parent_id')->nullable()->after('conversation_id')
                ->constrained('channels')->cascadeOnDelete();

            // The sidebar draws a container's children in their own order, so the index that
            // matters is per-parent rather than the server-wide one `position` already has.
            $table->index(['parent_id', 'position']);
        });

        Schema::table('channel_reads', function (Blueprint $table) {
            // "Take me straight to this discussion when I open the channel", per user, per
            // container. It lives here because channel_reads is already exactly the
            // user-by-channel row this preference needs, and a table of its own would be the
            // same unique key twice. Null means no preference — fall back to General.
            $table->foreignIdFor(Channel::class, 'default_child_id')->nullable()->after('last_read_message_id')
                ->constrained('channels')->nullOnDelete();
        });

        Schema::table('servers', function (Blueprint $table) {
            // Starts permissive, because that is the behaviour we want. The column exists on
            // day one anyway: an open discussion list is an unbounded thing anyone can spam,
            // and the moment a server needs the switch is the moment it is too late to add it
            // calmly.
            $table->string('discussion_creation')->default('everyone');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('discussion_creation');
        });

        Schema::table('channel_reads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_child_id');
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->dropIndex(['parent_id', 'position']);
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
