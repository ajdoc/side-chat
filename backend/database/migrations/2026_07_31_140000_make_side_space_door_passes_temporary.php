<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A password pass stops being a key and becomes a ticket.
 *
 * `passed` used to be a list of user ids: say the words once and the door opened for you from
 * then on, forever, exactly like a key the owner had handed out. That is not what a password on
 * a door means. A code you were told in a pinned message is a thing you present *at the door*,
 * and somebody who wandered in last week should not still be walking through it today.
 *
 * So the column changes shape — from `[7, 12]` to `{"7": 1753970000, "12": 1753970012}`, a unix
 * timestamp per person saying when their pass runs out. Membership in the list is no longer the
 * question; whether the clock has run is. See SideSpaceLock::activePasses.
 *
 * ## Why the old rows are simply dropped
 *
 * There is no honest conversion. A bare id carries no expiry, so it would have to be read as
 * either "already lapsed" or "lapsed at some invented future moment", and the first of those is
 * what emptying the column means anyway — with the advantage of leaving nothing behind in the
 * old shape for later code to misread as a timestamp. Everybody standing behind a password door
 * says the words once more, which is the entire point of the change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('side_space_locks')->update(['passed' => null]);
    }

    public function down(): void
    {
        DB::table('side_space_locks')->update(['passed' => null]);
    }
};
