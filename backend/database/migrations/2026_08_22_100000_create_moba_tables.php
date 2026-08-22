<?php

use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The MOBA's metagame — everything around a match, and nothing inside one.
 *
 * ## The division of labour
 *
 * The match itself runs in a Rust process at 30Hz and PHP never touches it. See MOBA.md: a
 * request/response stack has nowhere to put an authoritative simulation loop, which is exactly
 * why the game server exists as a separate program. What PHP owns is the part it is *good* at
 * and the game server is not — a queue, a roster, persistent rank, and a record of what
 * happened.
 *
 * The two halves meet twice, both one-way and neither on the hot path: a signed ticket lets a
 * player into a match, and a signed result comes back when it ends.
 *
 * ## Why a match is not an app row
 *
 * Every other app in this codebase stores its state under `channel_apps` and belongs to one
 * channel. A match does not: it is played by ten people who may be in different channels, it
 * outlives the channel it was launched from, and its results feed a rank that is per *user*.
 * So it gets its own tables, and the app channel is a place to launch and watch one rather than
 * the thing that owns it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moba_matches', function (Blueprint $table) {
            $table->id();
            // Where it was launched from. Nullable because a match outlives its channel: a
            // deleted channel must not take a played match's rank history with it.
            $table->foreignIdFor(Channel::class)->nullable()->constrained()->nullOnDelete();
            // 1..5. The sim scales waves and structure health from this, so it is a property of
            // the match rather than of the server that happened to run it.
            $table->unsignedTinyInteger('team_size');
            // 'queued' → waiting for seats; 'live' → the game server has it; 'finished';
            // 'abandoned' → nobody ever connected, or the server died holding it.
            $table->string('status')->default('queued');
            // Where the client should connect. Written by whoever assigns the match to a server.
            $table->string('server_address')->nullable();
            $table->unsignedTinyInteger('winning_team')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('moba_match_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('moba_match_id')->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            // 0 = Blue, 1 = Red. Matches the sim's `Team` ordering.
            $table->unsignedTinyInteger('team');
            // The seat. Stable for the match's life, and what a reconnecting player is put back
            // into — the game server addresses seats, not users.
            $table->unsignedTinyInteger('slot');
            $table->string('hero');

            // Filled in from the game server's result report. Nullable because a match that
            // never finished has none of them, and zero would be a lie.
            $table->unsignedInteger('kills')->nullable();
            $table->unsignedInteger('deaths')->nullable();
            $table->unsignedInteger('assists')->nullable();
            $table->unsignedInteger('gold')->nullable();
            $table->unsignedInteger('damage')->nullable();
            $table->integer('mmr_change')->nullable();
            $table->timestamps();

            // One seat per person per match, and one person per seat.
            $table->unique(['moba_match_id', 'user_id']);
            $table->unique(['moba_match_id', 'slot']);
        });

        Schema::create('moba_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->unique()->constrained()->cascadeOnDelete();
            // Everyone starts here. A single number rather than a tier, because tiers are a
            // presentation of a number and storing both invites them to disagree.
            $table->unsignedInteger('mmr')->default(1200);
            $table->unsignedInteger('games')->default(0);
            $table->unsignedInteger('wins')->default(0);
            $table->timestamps();
        });

        Schema::create('moba_queue_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->unique()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Channel::class)->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('team_size');
            $table->string('hero');
            // The MMR at the moment of queueing, copied rather than joined. Matchmaking sorts on
            // it every few seconds and a rating that shifted mid-search would make the ordering
            // unstable; it is also what a widening search window is measured against.
            $table->unsignedInteger('mmr');
            $table->timestamps();

            // The queue is per size: someone waiting for a 5v5 is not a candidate for a 1v1.
            $table->index(['team_size', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moba_queue_entries');
        Schema::dropIfExists('moba_profiles');
        Schema::dropIfExists('moba_match_players');
        Schema::dropIfExists('moba_matches');
    }
};
