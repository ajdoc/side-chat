<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which bot the server's automations speak as.
 *
 * They have to speak as *somebody*. A reminder posts as a system notice because the room is
 * what's talking (see PostReminder), but a welcome message is a greeting from the server's
 * bot — it wants a name, an avatar and the BOT badge, and a server that has named its bot
 * SavageVox should see SavageVox saying it.
 *
 * Reusing the Bot row rather than inventing a second kind of automated account means an
 * automation bot is already a member with channel access (CreateBotAction attaches it), is
 * already excluded from the things bots are excluded from, and can hold a token *as well*
 * if its owner also wants to drive it from outside. A webhook is not required: an
 * automation bot is driven in-process.
 *
 * One per server. Two would mean every automation had to say which bot it speaks as, for a
 * choice almost nobody wants to make twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->boolean('runs_automations')->default(false);
        });

        // Enforced in the application (SetAutomationBotAction) rather than by a partial
        // unique index: MySQL has no filtered indexes, and a unique on
        // (server_id, runs_automations) would also forbid a server having two *ordinary*
        // bots, which is exactly what the platform is for.
        Schema::table('bots', function (Blueprint $table) {
            $table->index(['server_id', 'runs_automations']);
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropIndex(['server_id', 'runs_automations']);
            $table->dropColumn('runs_automations');
        });
    }
};
