<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which apps a Side Desk shows, per surface.
 *
 * The Side Desk used to have four hard-coded tabs. It now has a catalogue — the board, notes,
 * docs and calendar, plus every interactive widget (kanban, poll, music, the games) promoted to
 * a full app — and a surface picks which of them it wants. This column is that pick: an ordered
 * JSON array of app ids.
 *
 * *Per surface, not per user*, deliberately. Everything else on a Side Desk is shared state —
 * one board, one note, one canvas, one calendar for everyone in the channel — and a tab strip
 * that differed from person to person would make "it's on the Kanban tab" untrue for whoever
 * you said it to. It's the same object being arranged, so it's arranged once.
 *
 * NULL means "never customised", which is not the same as "no apps": the client renders its
 * default set. Storing the defaults instead would freeze them, so a later release adding an app
 * to the default set couldn't reach any surface that had already loaded once.
 *
 * The Open Canvas is not listed and cannot be removed — see the client's app registry for why.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['channels', 'side_chats'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->json('desk_apps')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['channels', 'side_chats'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('desk_apps');
            });
        }
    }
};
