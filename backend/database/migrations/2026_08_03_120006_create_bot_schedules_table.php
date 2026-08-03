<?php

use App\Models\Channel;
use App\Models\Server;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Post this, every Monday at nine."
 *
 * Rows, not queued jobs — the opposite of PostReminder, and the difference is worth stating.
 * A reminder is one-shot and personal ("poke me in 20 minutes"), so the queue being its
 * storage is a fair trade. A schedule is standing configuration a server expects to see and
 * edit for months; losing the weekly headcount to a flushed Redis would be a bug, and a
 * schedule you can't list is one you can't turn off.
 *
 * `next_run_at` is stored rather than recomputed across every row each minute. The runner's
 * query is then an index range scan — "everything due" — instead of parsing a cron
 * expression per server per minute, and it stays that shape however many servers there are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Server::class)->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            // Null falls back to `bot_settings.reminder_channel_id` — so a server can move
            // every unassigned schedule at once rather than editing each.
            $table->foreignIdFor(Channel::class)->nullable()->constrained()->nullOnDelete();
            $table->text('body');

            // Standard five-field cron. The UI offers presets (daily, weekly, hourly) and
            // writes the expression, so nobody has to know the syntax — but the column
            // stays general, because the first person who wants "weekdays at 9" shouldn't
            // need a migration.
            $table->string('cron', 64);

            /*
             * Which clock "9:00" means.
             *
             * Stored per schedule, and not optional in practice: a server whose members are
             * all in one place expects nine in *their* morning, and computing due-ness in
             * UTC would drift by an hour twice a year for half the world.
             */
            $table->string('timezone', 64)->default('UTC');

            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            // The runner's whole query.
            $table->index(['enabled', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_schedules');
    }
};
