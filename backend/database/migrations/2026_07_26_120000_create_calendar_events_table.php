<?php

use App\Models\Channel;
use App\Models\SideChat;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Calendar app — a Side Desk's shared schedule. Like the whiteboard, the notes and the
 * canvas, an event hangs off exactly one surface: its `side_chat_id` *or* its `channel_id`.
 *
 * Times are stored as UTC timestamps and rendered in each viewer's own zone; an all-day event
 * still carries a real `starts_at` (midnight UTC of the day it's on) so one ordering works for
 * both kinds and the month grid doesn't need two code paths. `ends_at` is nullable — plenty of
 * entries are a moment, not a span.
 *
 * See {@see \App\Models\CalendarEvent}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(SideChat::class)->nullable()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Channel::class)->nullable()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            // One of a small named set the client maps to theme colours — not a hex, so the
            // palette can be re-tuned without rewriting every stored row.
            $table->string('color', 16)->default('primary');
            $table->timestamps();

            // The month grid always queries "this surface, this window", in start order.
            $table->index(['side_chat_id', 'starts_at']);
            $table->index(['channel_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
