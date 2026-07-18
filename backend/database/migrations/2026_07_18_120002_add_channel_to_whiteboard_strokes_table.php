<?php

use App\Models\Channel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generalise the whiteboard from a side-chat-only surface to any chat: a board can now hang
 * off a plain channel too. A stroke points at exactly one surface — its `side_chat_id` *or*
 * its `channel_id` — so `side_chat_id` becomes nullable and `channel_id` joins it.
 * See {@see \App\Models\WhiteboardStroke::streamName()}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whiteboard_strokes', function (Blueprint $table) {
            $table->foreignIdFor(Channel::class)->nullable()->after('side_chat_id')->constrained()->cascadeOnDelete();
            $table->index('channel_id');
        });

        // A stroke now belongs to a side chat *or* a channel, so side_chat_id is no longer
        // mandatory. (Raw ALTER: Postgres-native, and it leaves the existing FK untouched.)
        DB::statement('ALTER TABLE whiteboard_strokes ALTER COLUMN side_chat_id DROP NOT NULL');
    }

    public function down(): void
    {
        // Drop channel strokes first — they'd violate the restored NOT NULL on side_chat_id.
        DB::table('whiteboard_strokes')->whereNull('side_chat_id')->delete();
        DB::statement('ALTER TABLE whiteboard_strokes ALTER COLUMN side_chat_id SET NOT NULL');

        Schema::table('whiteboard_strokes', function (Blueprint $table) {
            $table->dropIndex(['channel_id']);
            $table->dropConstrainedForeignIdFor(Channel::class);
        });
    }
};
