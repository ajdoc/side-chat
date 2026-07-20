<?php

use App\Models\Channel;
use App\Models\SideChat;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Side Space note — one shared, collaboratively-edited markdown document per surface. Like
 * the whiteboard, a note hangs off exactly one surface: its `side_chat_id` *or* its
 * `channel_id`. Last write wins; there's no per-keystroke history. See {@see \App\Models\SpaceNote}.
 *
 * The two unique indexes cap a surface at one note. They sit on nullable columns, which
 * Postgres treats as distinct — so every channel note (null `side_chat_id`) and every side
 * chat note (null `channel_id`) coexists happily under them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('space_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(SideChat::class)->nullable()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Channel::class)->nullable()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('content')->default('');
            $table->timestamps();

            $table->unique('side_chat_id');
            $table->unique('channel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_notes');
    }
};
