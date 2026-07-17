<?php

use App\Models\SideChat;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wire messages into side chats.
 *
 * `side_chat_id` is to a side chat what `thread_id` is to a thread — a message belongs to
 * the main timeline, a thread, or a side chat, and the channel timeline query now excludes
 * both branches.
 *
 * `decided_at` / `decided_by` mark a message as a recorded *decision* — the ✅ count on the
 * side chat's living-object card. Modelled exactly like a pin (a nullable timestamp plus
 * who set it), because it is the same shape of thing: a flag any participant can toggle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignIdFor(SideChat::class)->nullable()->after('thread_id')->constrained()->cascadeOnDelete();
            $table->timestamp('decided_at')->nullable()->after('pinned_by');
            $table->foreignIdFor(User::class, 'decided_by')->nullable()->after('decided_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(SideChat::class);
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn('decided_at');
        });
    }
};
