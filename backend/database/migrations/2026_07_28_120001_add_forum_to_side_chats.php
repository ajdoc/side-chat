<?php

use App\Models\SideChat;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The forum layer on side chats: tags, and reactions to the post itself.
 *
 * A side chat already had almost everything a forum post needs — a title, an author, a
 * timeline of replies, counters. What it lacked was the two things that make a *list* of
 * them browsable: a way to say what a post is about, and a way to react to the post as a
 * whole rather than to some message inside it.
 *
 * `tags` is a JSON array of plain strings rather than a table of its own. A tag here is a
 * label somebody typed, not an entity with an id, a colour and an owner; the moment it
 * needs to be any of those this becomes a join table, and until then a normalised
 * catalogue would be three tables of ceremony around a word.
 *
 * `side_chat_reactions` mirrors `reactions` deliberately — same shape, same unique key,
 * same toggle semantics. Making the existing table polymorphic would have touched every
 * reaction query in the app to buy a saving of one small table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('side_chats', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('name');
        });

        Schema::create('side_chat_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(SideChat::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('emoji');
            $table->timestamps();
            // One of each emoji per person per post — the toggle's uniqueness, enforced
            // where it can't be raced rather than only in the action.
            $table->unique(['side_chat_id', 'user_id', 'emoji']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('side_chat_reactions');

        Schema::table('side_chats', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }
};
