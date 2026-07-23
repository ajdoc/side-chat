<?php

use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An effect attached to one *person*: what this call does when Ana in particular walks in.
 *
 * The channel's own join_effect/leave_effect stay as the room's default — what happens for
 * anybody nobody has singled out — and a row here overrides it for one person. That's the
 * whole model: a default plus exceptions, which is what lets an owner give one friend a
 * fanfare without deciding what happens for the other twenty members.
 *
 * Keyed by (channel, user) rather than by user alone, because an effect is a property of a
 * room's relationship to someone. The same person can walk into #gaming to fireworks and
 * into #standup to nothing at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_effect_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Channel::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();

            // Null on one side is meaningful: "fireworks when they arrive, nothing when they
            // go" is a perfectly ordinary thing to want, and is not the same as no row at all
            // (which falls back to the room's default).
            $table->string('join_effect', 32)->nullable();
            $table->string('leave_effect', 32)->nullable();
            $table->timestamps();

            // One assignment per person per room; setting theirs again edits it.
            $table->unique(['channel_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_effect_assignments');
    }
};
