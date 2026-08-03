<?php

use App\Models\Channel;
use App\Models\Server;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a server's bot behaves — the Configuration page, one row per server.
 *
 * A row rather than columns on `servers` because none of this is a fact about the server:
 * it's a fact about the bot running in it, most servers will never have one, and a server
 * without a bot should not be carrying eight null columns about a feature it doesn't use.
 *
 * The channel columns all null out rather than cascade-delete the row: deleting the channel
 * a bot announced in should switch announcements off, not wipe the server's whole bot
 * configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Server::class)->unique()->constrained()->cascadeOnDelete();

            /*
             * What a text command starts with, for the `!kick`-style commands that aren't
             * slash commands. One character, and configurable because the conventional
             * choice collides with whatever else a community already runs — a server with a
             * music bot on `!` needs to be able to move ours to `?`.
             */
            $table->string('command_prefix', 4)->default('!');

            // Where the bot posts each kind of thing. All optional; null means "don't".
            $table->foreignIdFor(Channel::class, 'mod_log_channel_id')->nullable()->constrained('channels')->nullOnDelete();
            $table->foreignIdFor(Channel::class, 'announcement_channel_id')->nullable()->constrained('channels')->nullOnDelete();
            // The fallback for a schedule that names no channel of its own.
            $table->foreignIdFor(Channel::class, 'reminder_channel_id')->nullable()->constrained('channels')->nullOnDelete();

            /*
             * Which roles may run the moderation commands.
             *
             * A list of `server_user.role` values, and empty means *nobody* — moderation
             * commands are off until somebody says who has them. The safer default: a bot
             * arriving with `!ban` already live for every admin is a surprise, and a
             * surprise in that direction is expensive.
             */
            $table->json('mod_roles')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_settings');
    }
};
