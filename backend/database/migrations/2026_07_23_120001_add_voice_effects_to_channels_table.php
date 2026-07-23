<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The room's own entrance and exit music: an effect everyone in the call sees and hears
 * when somebody walks in or out.
 *
 * A property of the *channel*, not of the person arriving — the point is that a room can
 * have a character, and that everyone in it experiences the same thing at the same moment.
 * Which is also why it's the owner's to set: an effect chosen by the arriver would be a
 * thing done *to* the room by anyone who fancied it.
 *
 * Stored as a short slug from a fixed catalogue (Channel::VOICE_EFFECTS) rather than
 * anything uploadable — every effect is drawn and synthesised in the browser, so there's
 * no asset to host, and no way to make the room play something nobody vetted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            // Null is "nothing happens", which is what every existing channel wants.
            $table->string('join_effect', 32)->nullable()->after('position');
            $table->string('leave_effect', 32)->nullable()->after('join_effect');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['join_effect', 'leave_effect']);
        });
    }
};
