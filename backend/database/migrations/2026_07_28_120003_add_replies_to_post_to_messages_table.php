<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "This message is a reply to the side chat *post* itself" — the forum's top-level reply.
 *
 * A flag rather than a foreign key, because the target is already known: a message in a
 * side chat carries `side_chat_id`, and there is exactly one post per side chat. A second
 * column pointing at the same row would be a duplicate that could disagree with the first.
 *
 * It is deliberately not `reply_to_id`. That column points at another *message*, and its
 * chip renders that message's author and body; a reply to the post has neither — it is
 * addressed at a title. Overloading one column with two meanings would mean every reader
 * of `reply_to_id` having to ask which kind it was holding.
 *
 * Meaningless outside a side chat, and nothing sets it elsewhere: a channel or thread
 * message has no post to reply to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('replies_to_post')->default(false)->after('reply_to_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('replies_to_post');
        });
    }
};
