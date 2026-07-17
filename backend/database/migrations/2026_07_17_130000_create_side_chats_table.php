<?php

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Side chats: a conversation spun off a message that behaves like a mini room — its own
 * timeline, its own participants, revisitable later like any other chat.
 *
 * Structurally a richer sibling of a thread: it branches off a message and lives inside a
 * channel, but unlike a thread it has a *roster* of its own (see side_chat_user) rather
 * than being open to everyone in the channel. Its messages carry `side_chat_id` the same
 * way thread replies carry `thread_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('side_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Channel::class)->constrained()->cascadeOnDelete();
            // Whoever spun it up. Kept even after they leave the roster — it's the "started by".
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            // The message it branched off (null if it was deleted, or a standalone side chat).
            $table->foreignIdFor(Message::class)->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('side_chats');
    }
};
