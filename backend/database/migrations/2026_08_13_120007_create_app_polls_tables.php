<?php

use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Polls app — a wall of polls, each with results, reactions and a comment thread.
 *
 * ## Why this isn't the poll widget
 *
 * There is already a `poll` widget: the single card a `p!` command drops into a timeline, whose
 * whole state is one JSON blob. It stays. This is a different thing that happens to share a
 * noun — a *place* where a channel's polls live, outliving the messages that would have
 * scrolled past, answerable days later, with a discussion under each one.
 *
 * Real tables rather than a widget blob because those are exactly the things a blob is bad at:
 * a vote has to be unique per person, results have to be counted rather than recomputed from an
 * array, and the comments hang off the polymorphic tables the other apps already use.
 *
 * ## Vote shape
 *
 * `type` decides how many options a person may pick — `yes_no` and `single` mean one, `multiple`
 * means any. That's enforced in the action rather than the schema (the unique index below is
 * per option, which is what stops the same option twice); the schema can't express "one row per
 * poll unless the poll says otherwise".
 *
 * A closed poll keeps its votes. Closing is about refusing new ones, not about forgetting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_polls', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Channel::class)->constrained()->cascadeOnDelete();
            $table->string('type', 16)->default('single');
            $table->string('question', 300);
            $table->text('description')->nullable();
            // Votes stay attached to a name unless this is set, in which case the client draws
            // totals only. Stored per poll because it's a property of the question being asked.
            $table->boolean('anonymous')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->foreignIdFor(User::class, 'created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The wall: this channel's polls, newest first, open ones first.
            $table->index(['channel_id', 'closed_at']);
        });

        Schema::create('app_poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('app_polls')->cascadeOnDelete();
            $table->string('label', 200);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['poll_id', 'position']);
        });

        Schema::create('app_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('app_polls')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('app_poll_options')->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->timestamps();

            // One person, one vote per option. Picking the same option twice is a double-click,
            // not an opinion held twice as strongly.
            $table->unique(['option_id', 'user_id']);
            // "What did I vote for on this poll", which every render of a poll asks.
            $table->index(['poll_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_poll_votes');
        Schema::dropIfExists('app_poll_options');
        Schema::dropIfExists('app_polls');
    }
};
