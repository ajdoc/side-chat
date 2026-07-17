<?php

use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comments: word-reactions. A short annotation on a message — feedback, not a conversation.
 *
 * Deliberately the reactions table with a body bolted on. The one thing that differs is the
 * unique key: a reaction is (message, user, emoji); a comment is (message, user, normalized
 * body, emoji), because "18 people said Looks good" only means something if each person is
 * counted once for that phrase. The normalization (trim + lowercase) is stored in
 * `body_key` so the database can enforce it — `body` keeps the original casing for display.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Message::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            // The annotation itself. Short by design — feedback, not a paragraph.
            $table->string('body', 120);
            // Normalized (trimmed + lowercased) form of the body, for grouping and the
            // one-per-person rule. Never shown; `body` is what the UI renders.
            $table->string('body_key', 120);
            // At most one emoji may lead a comment. Null when it's text only.
            $table->string('emoji', 32)->nullable();
            $table->timestamps();

            // One person co-signs a given phrase at most once — the toggle relies on this.
            $table->unique(['message_id', 'user_id', 'body_key', 'emoji']);
            $table->index(['message_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
