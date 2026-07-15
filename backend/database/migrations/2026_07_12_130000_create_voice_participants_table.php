<?php

use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Channel::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();

            // Broadcast so other members can render the right icons on a tile. These are
            // *self-reported* states — muting someone for yourself alone is a purely local
            // affair and deliberately never leaves the listener's browser.
            $table->boolean('muted')->default(false);
            $table->boolean('deafened')->default(false);
            $table->boolean('screen_sharing')->default(false);

            // A browser that crashes never sends "leave". Clients in a call heartbeat this
            // column, and anything older than config('webrtc.stale_after_seconds') is treated
            // as gone — see VoiceService::pruneStale().
            $table->timestamp('last_seen_at');
            $table->timestamps();

            // You are in a voice channel once, or not at all.
            $table->unique(['channel_id', 'user_id']);
            // "who is in this server's voice channels" — the sidebar roster, in one query.
            $table->index(['user_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_participants');
    }
};
