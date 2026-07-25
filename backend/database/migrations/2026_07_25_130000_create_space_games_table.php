<?php

use App\Models\Channel;
use App\Models\User;
use App\Services\Games\GameHandler;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A game running in a Side Space — the room turned into something you play.
 *
 * One row per channel (hence the unique key): a room holds at most one game at a time, and
 * proposing a new one replaces whatever finished before it. The row exists from the moment
 * someone *proposes* a game, through the vote, the play, and until the next proposal overwrites
 * it — `status` says which of those it's in.
 *
 * `state` is the game's own business and its shape is the handler's contract with the matching
 * client (see {@see GameHandler}); the table knows only that it's JSON. That
 * is what keeps this one table serving every game we ever add — a battle, a heist, a quiz — with
 * no migration per game. `votes` is the *start* vote alone (`{userId: bool}`); votes taken
 * during a game live in `state`, because their meaning is the game's, not the framework's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('space_games', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Channel::class)->unique()->constrained()->cascadeOnDelete();
            // Which game. Matches a handler's type() — 'amongus' today, more later.
            $table->string('type');
            // 'voting' → being put to the room; 'running' → live; 'ended' → finished, results up.
            $table->string('status')->default('voting');
            $table->json('state')->default('{}');
            // The start vote: {userId: true|false}. Cleared once the game starts.
            $table->json('votes')->default('{}');
            $table->foreignIdFor(User::class, 'created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_games');
    }
};
