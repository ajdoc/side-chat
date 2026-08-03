<?php

use App\Models\User;
use App\Services\Games\GameHandler;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A hero — the one thing in the dungeon crawl that outlives the dungeon.
 *
 * Every other game in a Side Space fits entirely in `space_games.state`, because every other game
 * *ends*: the round is the whole of it, and the row is overwritten by whatever the room plays
 * next. A crawl is the opposite shape. The run is disposable — a seed, some monsters, a floor you
 * cleared — but the character who walked into it is the point of playing at all, and one row per
 * channel that the next proposal clobbers is exactly the wrong home for a level 30 sorcerer.
 *
 * So the split is: the *run* lives in the game's state like every other game
 * ({@see GameHandler}), and the *hero* lives here, belonging to a user rather than a room. You
 * take the same character into any room's dungeon, and the run ending is not the character
 * ending.
 *
 * Several heroes per player, as the genre demands — you roll a new one to play a new way, and the
 * old one is still standing there when you come back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arpg_characters', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            // 'warrior' | 'rogue' | 'sorcerer' — what they're good at. The handler owns the list.
            $table->string('class', 20);
            $table->unsignedInteger('level')->default(1);
            $table->unsignedBigInteger('xp')->default(0);
            $table->unsignedBigInteger('gold')->default(0);
            // Rolled and spent attributes: strength, dexterity, magic, vitality, points unspent.
            $table->json('stats')->default('{}');
            // What they're carrying, and what they're wearing. Items are the game's own shape —
            // a generated affix roll, not a catalogue row — so they're JSON, like a game's state.
            $table->json('inventory')->default('[]');
            $table->json('equipment')->default('{}');
            // How far they've got, so a returning hero starts where they left off.
            $table->unsignedInteger('depth')->default(1);
            $table->timestamp('last_played_at')->nullable();
            $table->timestamps();

            // Your own roster, most recently played first — the character-select query.
            $table->index(['user_id', 'last_played_at']);
            // One name per player. Two "Conan"s in one roster is a UI you can't click.
            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arpg_characters');
    }
};
