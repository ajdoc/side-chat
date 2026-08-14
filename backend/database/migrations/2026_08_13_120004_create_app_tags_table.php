<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tags, and what wears them.
 *
 * Two tables for the same reason the comments are polymorphic: a tag is a channel-wide
 * vocabulary ("bug", "design", "blocked") and the *things* tagged are whatever the apps own —
 * a tracker task today, a kanban card and a calendar entry next. A per-app tag table would
 * mean re-typing the same word in every app and no way to ask "everything tagged blocked".
 *
 * Tags belong to the **channel**, not to a project: a tracker channel with three projects
 * shares one vocabulary, which is what makes a tag worth filtering on. `name` is unique per
 * channel case-insensitively — enforced on a lowercased column so "Bug" and "bug" can't both
 * exist, while `label` keeps whatever capitalisation was typed for display.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            /** The lookup/uniqueness form: lowercased, trimmed. */
            $table->string('name', 40);
            /** What gets drawn — the capitalisation whoever created it used. */
            $table->string('label', 40);
            // A named colour the client maps to theme tokens, not a hex — same reasoning as the
            // calendar's, so the palette can be re-tuned without rewriting stored rows.
            $table->string('color', 16)->default('slate');
            $table->timestamps();

            $table->unique(['channel_id', 'name']);
        });

        Schema::create('app_taggables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained('app_tags')->cascadeOnDelete();
            $table->morphs('taggable');
            $table->timestamps();

            // A thing wears a tag once. Without this a double-click is two rows and the chip
            // renders twice.
            $table->unique(['tag_id', 'taggable_type', 'taggable_id'], 'app_taggables_unique');
            $table->index(['taggable_type', 'taggable_id'], 'app_taggables_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_taggables');
        Schema::dropIfExists('app_tags');
    }
};
