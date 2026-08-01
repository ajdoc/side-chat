<?php

use App\Models\Bot;
use App\Models\Server;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The slash commands a bot answers to.
 *
 * Registered by the bot itself rather than configured by the server's owner: only the bot
 * knows what it can do, and a list maintained by hand somewhere else is a list that goes
 * stale the first time the bot is updated.
 *
 * `server_id` is copied down from the bot so the unique index can span the server. Two bots
 * in one server both claiming `/deploy` has no good answer at *call* time — one of them
 * would silently never fire — so the collision is refused at registration, where there's
 * somebody to tell.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Bot::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Server::class)->constrained()->cascadeOnDelete();
            $table->string('name', 32);
            $table->string('description', 255)->nullable();
            $table->string('usage', 255)->nullable();
            $table->timestamps();

            // One `/name` per server, whoever claimed it first.
            $table->unique(['server_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_commands');
    }
};
