<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emoji reactions on anything an app owns.
 *
 * The fourth of the shared app tables, alongside comments, tags and activity, and added for the
 * same reason: a poll wants reactions, a tracker task will, and a per-app reactions table would
 * be the same four columns written out once per app.
 *
 * Deliberately *not* {@see \App\Models\Reaction}, which is keyed to `message_id`. Same idea,
 * different subject — and the timeline's reactions carry behaviour this doesn't need (they feed
 * reaction-roles, and they're part of a message's broadcast payload).
 *
 * `channel_id` is denormalised alongside the polymorphic target exactly as the comments do, so
 * authorisation is one column rather than a walk up through whichever app owns the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_reactions', function (Blueprint $table) {
            $table->id();
            $table->morphs('reactable');
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('emoji', 32);
            $table->timestamps();

            // One person, one of each emoji, per thing. Clicking twice removes it rather than
            // counting twice — the toggle the client already expects.
            $table->unique(['reactable_type', 'reactable_id', 'user_id', 'emoji'], 'app_reactions_unique');
            $table->index(['reactable_type', 'reactable_id'], 'app_reactions_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_reactions');
    }
};
