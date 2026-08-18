<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The other direction: an app item, and the chat about it.
 *
 * "Add to app" turns a message into a task. This is the return trip — a task, card, poll or
 * event that needs talking about gets a **side chat**, and the item and the room point at each
 * other. Before this, an item's discussion lived in `app_comments` and was invisible from the
 * timeline: "can we talk about ONB-4" meant pasting a key into chat by hand.
 *
 * ## Why a side chat rather than a thread
 *
 * A side chat is the room this product already has for deciding something — it has
 * participants, decisions, its own desk. A thread is a tangent off one message. An app item is
 * a thing being *worked on*, so it gets the room.
 *
 * ## Why a pointer table
 *
 * The link has to hang off any of eight item kinds, which is what the polymorphic app tables
 * already do — and putting a `side_chat_id` column on each of those tables would be eight
 * migrations to say one thing. One row per item (the unique index), because "the discussion
 * about this card" is singular: a second one would split the conversation in exactly the way
 * this exists to prevent.
 *
 * The side chat outlives the item. Deleting a card drops this pointer (see
 * `HasAppActivity::purgeAppActivityFor`) and leaves the room, because the conversation happened
 * and the people in it did not consent to losing it. Deleting the *side chat* drops the row on
 * the foreign key: there is nothing left to point at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_discussions', function (Blueprint $table) {
            $table->id();
            // The short morph name — 'tracker_task', 'kanban_card'. See AppSubjects.
            $table->morphs('subject');
            // Denormalised, like `app_comments.channel_id`: every read here is scoped to a
            // channel for authorisation, and walking back up through eight different owners to
            // learn one number that never changes is a join per read for nothing.
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('side_chat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One discussion per item.
            $table->unique(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_discussions');
    }
};
