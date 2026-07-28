<?php

use App\Models\Channel;
use App\Models\SideChatForum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forums: the named, ordered groups a channel's side chats are filed under.
 *
 * The tag layer already answered "what is this post about". It could not answer "where in
 * the list does it belong", and the difference is not pedantic: a tag is authored by
 * whoever writes the post and exists only while some post carries it, so a tag can't be
 * created empty, can't be renamed without touching every post, can't be put in an order,
 * and can't be curated by the people who run the server. A forum is all four. They coexist
 * because they are different questions — a post sits in exactly one forum and carries any
 * number of tags.
 *
 * `forum_id` is nullable and nulls on delete rather than cascading. Deleting a forum is a
 * change to how the list is *arranged*; it must never be a way to delete a channel's
 * conversations. The posts fall back into the "Uncategorised" group, which is not a row in
 * this table — it's simply the posts with no forum, so it can't be renamed, reordered or
 * deleted, and it can't go missing.
 *
 * `position` orders the groups. Explicit rather than by name or by age, because the order
 * of a forum list is an editorial decision — "Announcements" belongs at the top whatever
 * it's called and whenever it was made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('side_chat_forums', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Channel::class)->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            // Two forums with the same name in one channel are two groups nobody can tell
            // apart — the name is the whole of a group's identity in the list.
            $table->unique(['channel_id', 'name']);
            $table->index(['channel_id', 'position']);
        });

        Schema::table('side_chats', function (Blueprint $table) {
            $table->foreignIdFor(SideChatForum::class)
                ->nullable()
                ->after('channel_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('side_chats', function (Blueprint $table) {
            $table->dropConstrainedForeignId('side_chat_forum_id');
        });

        Schema::dropIfExists('side_chat_forums');
    }
};
