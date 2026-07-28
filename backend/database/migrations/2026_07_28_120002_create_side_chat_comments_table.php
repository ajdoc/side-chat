<?php

use App\Models\SideChat;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comments ("word-reactions") on a side chat *post*.
 *
 * The sibling of `side_chat_reactions`, and the same trade for the same reason: a mirror of
 * the `comments` table rather than making that one polymorphic, which would have touched
 * every comment query in the app to save one small table.
 *
 * `body_key` is the normalised grouping key — what makes "Looks good" and "looks good "
 * one chip instead of two. The unique index is over (post, person, phrase, emoji), which is
 * exactly the toggle's identity: co-signing a phrase you've already co-signed takes it back,
 * and the constraint means two clicks racing can't leave you co-signing it twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('side_chat_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(SideChat::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('body');
            $table->string('body_key');
            $table->string('emoji')->nullable();
            $table->timestamps();
            $table->unique(['side_chat_id', 'user_id', 'body_key', 'emoji']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('side_chat_comments');
    }
};
