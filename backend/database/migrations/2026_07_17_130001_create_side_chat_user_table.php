<?php

use App\Models\SideChat;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The roster of a side chat. This pivot is the whole reason a side chat isn't just a thread:
 * a thread is open to everyone in the channel, a side chat has a guest list you Join.
 *
 * Anyone in the channel may *read* a side chat, but only the people on this roster may post
 * in it, pin in it, or record a decision — which is what the [Join] button buys you.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('side_chat_user', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(SideChat::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            // 'owner' for the creator, 'member' for everyone who joined. Room for moderation later.
            $table->string('role')->default('member');
            $table->timestamps();

            $table->unique(['side_chat_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('side_chat_user');
    }
};
