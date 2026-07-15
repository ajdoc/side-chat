<?php

use App\Models\Conversation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A channel's container becomes either a server or a conversation.
 *
 * This is the whole trick behind DMs and group chats. Every feature the app has —
 * messages, replies, threads, reactions, read receipts, pins, attachments, link previews,
 * typing, and the voice/video call itself — already hangs off `channel_id`. Give a
 * conversation a channel of its own and all of it works in a DM with no further changes;
 * build DMs on a parallel `conversation_messages` table instead and every one of those
 * features has to be written a second time.
 *
 * So a channel now belongs to exactly one of the two, and the CHECK constraint below says
 * so out loud. `Channel::container()` is the seam everything else goes through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->foreignId('server_id')->nullable()->change();
            $table->foreignIdFor(Conversation::class)->nullable()->after('server_id')
                ->constrained()->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE channels ADD CONSTRAINT channels_one_container CHECK (
                (server_id IS NOT NULL AND conversation_id IS NULL)
                OR (server_id IS NULL AND conversation_id IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE channels DROP CONSTRAINT channels_one_container');

        Schema::table('channels', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(Conversation::class);
            $table->foreignId('server_id')->nullable(false)->change();
        });
    }
};
