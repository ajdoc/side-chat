<?php

use App\Models\SideChat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A side chat can own threads of its own, the same way the channel does — a reply tree spun
 * off one of its messages, kept out of the channel's own thread list. Most threads have no
 * side chat (`null`); those that do belong to the side chat's workspace, not the channel's
 * Threads panel. The channel thread query filters on `whereNull('side_chat_id')` to keep
 * the two lists apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->foreignIdFor(SideChat::class)->nullable()->after('channel_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(SideChat::class);
        });
    }
};
