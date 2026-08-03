<?php

use App\Models\Automation;
use App\Models\Server;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "When X happens, do Y" — configured by a server's owner and run by us.
 *
 * The existing bot platform (see BOTS.md) can already do all of this, but only if somebody
 * writes and hosts a program. That's the right ceiling for a deploy bot and much too high
 * for "greet people when they join". An automation is the same idea with the program part
 * replaced by rows.
 *
 * Three columns carry the shape:
 *
 *  - `trigger` is one name from the trigger registry. Stored as a string rather than an
 *    enum column because the registry is code and gains entries between migrations; an
 *    automation whose trigger no longer exists is skipped at fan-out and still shown to its
 *    owner, which beats a migration that has to rewrite people's rules.
 *  - `trigger_config` narrows the trigger itself where it needs narrowing — which channel a
 *    message must be in, which emoji was reacted with.
 *  - `conditions` is a flat list of `{field, operator, value}` predicates over the event's
 *    context. Flat, and evaluated left to right with AND, on purpose: the moment this grows
 *    parentheses and OR it becomes a language, and a language needs a parser, an editor and
 *    an error report nobody wants to write. Anything that genuinely needs OR is two rules.
 *
 * Actions live in their own table because they're ordered and there are several — see the
 * automation_actions migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Server::class)->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('trigger', 64);
            $table->json('trigger_config')->nullable();
            $table->json('conditions')->nullable();
            $table->boolean('enabled')->default(true);

            /*
             * A built-in is an automation the dashboard renders on its own page — the
             * welcome message, a reaction-role grant — rather than in the generic list. The
             * column holds which feature owns it, so that page can find its row and so the
             * generic list can leave it alone. Null is an ordinary user-made rule.
             *
             * It is otherwise not special: it fires through the same engine, which is the
             * whole point. A built-in that took a private path would drift from the thing
             * users can build, and then "why can't my rule do what the welcome message
             * does" becomes a real question with no good answer.
             */
            $table->string('builtin', 32)->nullable();

            // Shown on the dashboard. An automation that has never run usually means its
            // trigger isn't firing, and that's the first thing anybody debugging asks.
            $table->unsignedInteger('run_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            // Fan-out reads exactly this: every enabled rule in this server for this trigger.
            $table->index(['server_id', 'trigger', 'enabled']);
            // One row per built-in feature per server — the welcome message is a setting,
            // not a list.
            $table->unique(['server_id', 'builtin']);
        });

        Schema::create('automation_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Automation::class)->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->json('config')->nullable();
            // Actions run in this order and it matters: "grant the badge, then announce it"
            // reads wrong the other way round.
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['automation_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_actions');
        Schema::dropIfExists('automations');
    }
};
