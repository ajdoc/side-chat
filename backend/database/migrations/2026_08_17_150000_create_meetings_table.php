<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A meeting: a room, a link to it, and who came in through the link.
 *
 * ## Why this table exists now, having argued against it
 *
 * When meetings were only ever *scheduled*, a meeting was a calendar entry with a room and a
 * table would have been a second concept disagreeing with the calendar about "when". Two
 * requirements changed that, and neither is about time:
 *
 *  - **A meeting can have no schedule at all.** "Make me a link, we're starting now" has nothing
 *    to hang off a calendar entry, and inventing an entry for it would put a phantom in
 *    everybody's month view.
 *  - **A link is a thing with a policy.** Who may follow it, whether it admits people from
 *    outside, when it stops working — none of which a calendar entry has anywhere to keep.
 *
 * So the row is the *link and its policy*, `scheduled_event_id` points at the calendar entry when
 * there is one, and the calendar remains the only place that knows about time. A meeting with a
 * schedule is both rows, agreeing, because one of them is a pointer.
 *
 * ## The room is a channel, as ever
 *
 * `channel_id` is a voice channel or a Side Space — inside a server if the meeting was made
 * there, otherwise the channel of a group conversation created for it. Nothing about a meeting's
 * *room* is new: it's the same call machinery, the same roster, the same Side Desk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            // The link. A uuid rather than the id, because a meeting link is pasted into other
            // people's chats and an enumerable one would let anybody walk the list.
            $table->uuid('token')->unique();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            /*
             * May somebody who isn't already in the room follow this link in?
             *
             * Only meaningful for a meeting in a **group conversation**, which is why the flag
             * lives here rather than being inferred: a link cannot admit anybody to a *server*
             * room, because being in a server is a thing a server's people decide, and a meeting
             * link is not a back door into one. The API refuses it rather than silently ignoring
             * it — see MeetingController.
             */
            $table->boolean('allow_external')->default(false);
            // The calendar entry, when this meeting is scheduled. Null is "starting whenever
            // somebody opens it", which is most of them.
            $table->foreignId('scheduled_event_id')->nullable()->constrained('calendar_events')->nullOnDelete();
            // After this, the link stops admitting people. The room and its history stay.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['channel_id', 'created_at']);
        });

        /*
         * Who came in, and how — the audit.
         *
         * A meeting link is the one door in this app that a stranger can walk through, so the
         * question "who is in here and how did they get in" has to have an answer that outlives
         * the call. Membership alone can't give it: somebody admitted by a link and somebody who
         * was already in the group look identical on the roster afterwards.
         *
         * One row per person per meeting (the unique index), stamped on their first arrival —
         * this is a record of admission, not of attendance, and re-joining after a dropped
         * connection is not a second event worth logging.
         */
        Schema::create('meeting_joins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // 'link' — admitted by following it; 'member' — already had access to the room.
            $table->string('via', 16);
            // Whether they were outside the room's people before this. The fact the audit is
            // actually for.
            $table->boolean('external')->default(false);
            // Coarse forensics, and no more: enough to tell two strangers apart in a log, not
            // enough to be a tracking record. Nullable because a request may have neither.
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_joins');
        Schema::dropIfExists('meetings');
    }
};
