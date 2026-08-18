<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guests: a link anybody can walk through.
 *
 * ## A guest is a User, for the same reason a bot is
 *
 * Every seat in this app is a `users` row — `voice_participants.user_id`, `messages.user_id`, the
 * conversation member list are all foreign keys to it. A genuinely anonymous participant would
 * mean making that column nullable in the roster, the timeline and the membership pivot, which is
 * surgery on the spine of the product to support the least trusted person in the building.
 *
 * So a guest is the trick `is_bot` already established: an account with no password, made by the
 * thing that needed it. Presence, the roster, push, the audit and every membership check keep
 * working because none of them can tell the difference — and the places that *must* tell the
 * difference ask `is_guest`.
 *
 * `guest_expires_at` is what stops this becoming litter: a guest account is made for one meeting
 * and is rubbish afterwards, so it carries its own use-by date and a command sweeps them.
 *
 * ## Access is three answers, not a boolean
 *
 * `allow_external` could only say "people with accounts, from outside". The question is really
 * *how far open is this door*, and it has three answers:
 *
 *   - `members` — only people already in the room. The default, and what a link is for sharing
 *     the address with people who could already come.
 *   - `account`  — anybody signed in. They join the meeting's group chat.
 *   - `guest`   — anybody at all: a name, and they're in. No account, no sign-up.
 *
 * A boolean with a second boolean beside it would have made "account but not guests" and "guests
 * but not accounts" both expressible, and one of those is nonsense.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_guest')->default(false)->after('is_bot');
            // Null for everybody who isn't a guest. Indexed because the sweeper's whole query is
            // "expired guests", and it runs on a schedule forever.
            $table->timestamp('guest_expires_at')->nullable()->index()->after('is_guest');
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->string('access', 16)->default('members')->after('title');
        });

        // Carry the boolean's meaning across before dropping it: a meeting that admitted people
        // from outside admitted people *with accounts*, which is exactly the middle level.
        DB::table('meetings')->where('allow_external', true)->update(['access' => 'account']);

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('allow_external');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->boolean('allow_external')->default(false);
        });

        DB::table('meetings')->whereIn('access', ['account', 'guest'])->update(['allow_external' => true]);

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('access');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_guest', 'guest_expires_at']);
        });
    }
};
