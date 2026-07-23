<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sharing sound without sharing the picture — playing a track to the room, or a video's
 * audio while everyone keeps their own screen. It rides the same pre-negotiated
 * screen-audio slot a screen share uses (see useVoice.ts), so the only thing missing was
 * a way to say so: `screen_sharing` would light a "watch my screen" prompt for a picture
 * that is never coming.
 *
 * Self-reported like its neighbours, and for the same audience: people *outside* the call
 * whose sidebar needs to know without joining it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_participants', function (Blueprint $table) {
            $table->boolean('audio_sharing')->default(false)->after('camera_on');
        });
    }

    public function down(): void
    {
        Schema::table('voice_participants', function (Blueprint $table) {
            $table->dropColumn('audio_sharing');
        });
    }
};
