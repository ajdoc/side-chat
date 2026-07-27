<?php

use App\Models\SideSpaceMap;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who owns a room, and which doors are locked.
 *
 * ## Why these aren't columns on the map
 *
 * Everything else about a Side Space — the ground, the zones, the furniture, the entrance —
 * lives in `side_space_maps` as JSON, and the obvious thing would be two more keys in it. That
 * would be a hole. The map is saved by `PUT /space/map`, which any *member* may call: the room
 * is built by the group, which is the point of it. A lock stored in that payload is a lock any
 * member can delete by saving the room around it, and an owner stored there is an owner anybody
 * can appoint themselves.
 *
 * So the two things that are *about permission* are kept out of the document that permission
 * doesn't guard. They're rows, with their own endpoints and their own gates, and a map save
 * never touches them.
 *
 * ## The join is by string id, on purpose
 *
 * A zone and a piece of furniture are identified inside the map's JSON by an id its author
 * generated (`z-4f2`, `d-k3f`). These tables point at those strings rather than at anything
 * relational, because there is nothing relational to point at — the zone is a key in an array.
 * The consequence is that a room can be *deleted out from under* its ownership row: erase the
 * zone in the editor and the row survives, pointing at nothing.
 *
 * That's deliberate, and the safer direction of the two. Redrawing a room you own must not
 * silently hand it to nobody, and a lock whose door is temporarily lifted while somebody
 * rearranges the wall should still be there when the door goes back. Rows pointing at ids that
 * no longer exist are inert — every read resolves them against the current map and skips what
 * doesn't resolve — and the management screens are where a stale one becomes visible and
 * removable.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * A zone with somebody responsible for it.
         *
         * Only zones can be owned. A Side Space's zones are already its *rooms* — a named,
         * bounded area that sound doesn't cross — so "room owner" needed no new concept, just
         * somebody's name against one.
         */
        Schema::create('side_space_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(SideSpaceMap::class)->constrained()->cascadeOnDelete();
            // The zone's id within the map's `zones` JSON.
            $table->string('zone_id', 40);
            // Null once they've left the server: the room stays, unowned, rather than vanishing.
            $table->foreignIdFor(User::class, 'owner_id')->nullable()->constrained()->nullOnDelete();
            // Who appointed them. Always a server owner — kept for the audit, not for a rule.
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One owner per room. Reassigning updates the row rather than stacking a second.
            $table->unique(['side_space_map_id', 'zone_id']);
        });

        /*
         * A locked door.
         *
         * One row per door — a door is locked or it isn't, and two locks on one door would be a
         * question about which wins that nobody wants to answer.
         */
        Schema::create('side_space_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(SideSpaceMap::class)->constrained()->cascadeOnDelete();
            // The door's id within the map's `objects` JSON.
            $table->string('object_id', 40);
            /*
             * The room this door was guarding when the lock was set.
             *
             * Stored rather than recomputed, because it's the answer to "whose lock is this" and
             * that must not change under somebody's feet when a wall moves. Recomputed only when
             * a lock is created.
             */
            $table->string('zone_id', 40)->nullable();
            // Who set it — the whole of requirement 5: a room owner lists their own locks.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            /*
             * User ids allowed through, beyond the people who are allowed through anything: the
             * person who set it, the room's owner, and the server's owner. Those three are
             * resolved at read time rather than copied in here, so a change of room owner doesn't
             * leave the old one holding a key.
             */
            $table->json('allowed');
            $table->timestamps();

            $table->unique(['side_space_map_id', 'object_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('side_space_locks');
        Schema::dropIfExists('side_space_rooms');
    }
};
