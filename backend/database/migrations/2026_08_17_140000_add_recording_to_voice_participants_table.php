<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is recording this call.
 *
 * A flag on the *participant*, exactly like `screen_sharing` and `camera_on`, and for the same
 * reason: recording is something a person in the room is doing, not a property of the room. Two
 * people can record the same call, one can stop while the other continues, and both are answered
 * by their own row — where a single `recording_by` on the channel would have to pick a winner.
 *
 * It also means the indicator is already solved. Every client renders participant flags off the
 * roster it holds, and `VoiceStateUpdated` already fans that out, so "somebody is recording" is
 * visible to the whole room through the machinery that shows who is muted. **That visibility is
 * the point, not a nicety**: a call recorded without the room knowing is a different product, and
 * the flag is what makes the badge impossible to skip.
 *
 * The bytes are not here. The recording is mixed and encoded in the browser and lands as a file
 * in the channel afterwards — see the client's useCallRecorder. This column is the state everyone
 * else needs *while* it's happening, which is the half the server has to own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_participants', function (Blueprint $table) {
            $table->boolean('recording')->default(false)->after('audio_sharing');
        });
    }

    public function down(): void
    {
        Schema::table('voice_participants', function (Blueprint $table) {
            $table->dropColumn('recording');
        });
    }
};
