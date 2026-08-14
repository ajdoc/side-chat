<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A thread of comments on *anything* an app owns.
 *
 * Polymorphic from the first line rather than a `tracker_comments` table that later grows a
 * sibling. The Tracker needs comments on a task; a kanban card, a calendar entry and a canvas
 * note all want the same affordance, and three tables holding the same five columns would be
 * three sets of endpoints, three resources and three broadcast paths for one idea.
 *
 * Deliberately *not* {@see \App\Models\Comment}, which is the reaction-style comment on a
 * timeline message: that one is keyed to `message_id`, groups by a normalized body to count
 * repeats, and carries an emoji. Same word, different feature — this is a discussion under a
 * work item, and merging them would mean one table where half the columns are null for half
 * the rows.
 *
 * `channel_id` is denormalised alongside the polymorphic target so authorisation is one join
 * away rather than a walk up through whichever app owns the row. Every gate asks the same
 * question — may this person see this channel — and this is what lets it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_comments', function (Blueprint $table) {
            $table->id();
            $table->morphs('commentable');
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            // The only read there is: one item's comments, oldest first.
            $table->index(['commentable_type', 'commentable_id', 'created_at'], 'app_comments_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_comments');
    }
};
