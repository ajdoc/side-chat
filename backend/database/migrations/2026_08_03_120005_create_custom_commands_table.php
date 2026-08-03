<?php

use App\Models\Badge;
use App\Models\Server;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A command a server invents for itself: `/rules`, `!discord`, `/ip`.
 *
 * The overwhelming majority of what people want a bot for is this — a canned answer to a
 * question that gets asked twice a week. It needs no program and no webhook, so it should
 * need no bot either; the response is a row.
 *
 * Two shapes, because communities are split on which they use and both are cheap:
 *
 *  - **slash** (`/rules`) resolves through SlashCommandService, in a step *between* the
 *    built-ins and the bot-registered commands. Built-ins still win — a server can't
 *    redefine `/help` and quietly become the only way to find out what anything does —
 *    but a custom command beats a bot's, because a thing the server itself declared should
 *    not be shadowed by something a bot registered later.
 *  - **prefix** (`!rules`) is matched against the server's configured prefix. See
 *    CommandParser for why this needs the server, unlike every other command shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Server::class)->constrained()->cascadeOnDelete();
            $table->string('name', 32);
            // 'slash' | 'prefix' | 'both'. Stored rather than derived so a server can move
            // a command from `!` to `/` without renaming it.
            $table->string('kind', 8)->default('both');
            $table->string('description', 255)->nullable();
            $table->text('response');

            /*
             * Only members holding this badge may run it.
             *
             * Null is "anybody". A badge grants nothing on its own (see the badges
             * migration) — but *this* is the one place it decides something, and it's a
             * deliberately weak decision: the worst a locked command can do is refuse to
             * print a canned message. Anything that actually needs authority is a
             * moderation command, gated on `bot_settings.mod_roles` instead.
             */
            $table->foreignIdFor(Badge::class, 'required_badge_id')->nullable()->constrained('badges')->nullOnDelete();

            /*
             * Seconds one person must wait before running it again. Zero is no limit.
             *
             * Per person rather than per channel: the thing being prevented is one member
             * spamming `!ip` twenty times, and a channel-wide lock would punish everybody
             * else for it.
             */
            $table->unsignedSmallInteger('cooldown_seconds')->default(0);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamps();

            // One name per server, whichever shape it takes — `/rules` and `!rules` are the
            // same command wearing two hats, and two rows would be two answers to it.
            $table->unique(['server_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_commands');
    }
};
