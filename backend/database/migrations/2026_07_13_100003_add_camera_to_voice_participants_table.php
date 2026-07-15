<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Until now "video" in a call meant a screen share and nothing else. A call in a DM is
 * the first place someone actually wants to be *seen*, so a camera is its own thing: you
 * can be on camera and sharing your screen at the same time, and they arrive over two
 * separate pre-negotiated video slots (see useVoice.ts).
 *
 * Self-reported, like the rest of these, and for the same audience: people *outside* the
 * call, whose sidebar needs to know without joining it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_participants', function (Blueprint $table) {
            $table->boolean('camera_on')->default(false)->after('screen_sharing');
        });
    }

    public function down(): void
    {
        Schema::table('voice_participants', function (Blueprint $table) {
            $table->dropColumn('camera_on');
        });
    }
};
