<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A calendar entry that shows up before it happens, and says where.
 *
 * The Calendar app has been a place to *write down* that something is at three o'clock, and
 * nothing more: nobody who wasn't looking at the tab found out. So an entry gains the two things
 * that close the gap between "we scheduled it" and "we're in it":
 *
 * - `remind_minutes` — post a notice in the channel this many minutes before it starts. Opt-in
 *   per entry, because most calendar rows are records rather than appointments, and a channel
 *   that announced all of them would be a channel people mute.
 * - `room_channel_id` — the voice room or Side Space it happens in. The reminder then names it,
 *   which is what turns a notice into a way in.
 *
 * `reminded_at` is the idempotency: the runner marks a row before it posts, so a slow post or a
 * crashed worker can miss a reminder but can never repeat one. A duplicate "starting in 10
 * minutes" is worse than a missing one — the first is noise everybody has to read twice, the
 * second is the calendar tab, which is where the entry was anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            // Nullable means "no reminder", which is what every existing row gets — a migration
            // that started announcing people's old entries would be a spam incident.
            $table->unsignedSmallInteger('remind_minutes')->nullable()->after('color');
            $table->timestamp('reminded_at')->nullable()->after('remind_minutes');
            /*
             * Where it happens, when that's a room in this app.
             *
             * Null on purpose for everything else: "the standup" has a room, "Ana's last day"
             * does not, and inventing one would put a join button on a birthday. Set null on
             * delete rather than cascading — losing the *event* because its voice channel was
             * tidied would be the more surprising outcome by far.
             */
            $table->foreignId('room_channel_id')->nullable()->after('reminded_at')
                ->constrained('channels')->nullOnDelete();
        });

        // The runner's whole query: rows still owing a reminder, in start order. Partial index
        // rather than one over every event, because the set that matters is tiny and shrinking
        // — a reminder is due once and then never again.
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->index(['reminded_at', 'starts_at'], 'calendar_events_pending_reminder_index');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropIndex('calendar_events_pending_reminder_index');
            $table->dropConstrainedForeignId('room_channel_id');
            $table->dropColumn(['remind_minutes', 'reminded_at']);
        });
    }
};
