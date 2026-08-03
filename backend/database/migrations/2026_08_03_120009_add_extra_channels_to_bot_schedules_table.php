<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A schedule can post to several channels.
 *
 * An announcement that belongs in #general *and* #announcements was two schedules with the
 * same words, which then drift apart the first time somebody edits one.
 *
 * Added alongside `channel_id` rather than replacing it, deliberately. `channel_id` is a real
 * foreign key with a real `nullOnDelete` — deleting a channel correctly drops it from the
 * schedule — and swapping that for a JSON array would trade a database guarantee for
 * application code that has to remember. So the first channel keeps its column and its
 * cascade, and this holds any *extra* ones.
 *
 * The cost is that the extras don't cascade, so the runner filters out ids that no longer
 * resolve. That's a cheap check on a list that is almost never longer than three, and it
 * beats losing the constraint on the common case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_schedules', function (Blueprint $table) {
            $table->json('extra_channel_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bot_schedules', function (Blueprint $table) {
            $table->dropColumn('extra_channel_ids');
        });
    }
};
