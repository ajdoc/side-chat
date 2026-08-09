<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What to tell someone by default, before they've said anything about a particular place.
 *
 * Two defaults rather than one, because the honest default differs by kind of room. A
 * server channel you are one of two hundred people in is noise unless it names you; a DM
 * was addressed to you by definition. Starting both at "everything" trains people to turn
 * notifications off wholesale, which is the outcome worth avoiding.
 *
 * A per-channel override lives on `channel_reads` and beats these — see that migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('notify_channel_default', 16)->default('mentions');
            $table->string('notify_dm_default', 16)->default('all');
            // The master switch for *push* specifically. Distinct from the levels above,
            // which also govern in-app and desktop alerts: "stop buzzing my phone" and
            // "stop telling me about this channel" are different requests.
            $table->boolean('push_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notify_channel_default', 'notify_dm_default', 'push_enabled']);
        });
    }
};
