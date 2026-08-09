<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where to reach someone who isn't looking at the app.
 *
 * A row per install, not per user: the token is minted by the device and is the address
 * a push is sent to, so it is the natural primary identity here and `user_id` is the part
 * that changes. Hence the unique index on `token` and an upsert on register — a phone that
 * signs out and back in as somebody else must move, not accumulate.
 *
 * `platform` is carried because the three surfaces are not one mechanism: `android` is an
 * FCM device token, `web` a Web Push subscription, and desktop needs neither (the Electron
 * shell stays resident and keeps its websocket). The sender branches on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('token', 512)->unique();
            $table->string('platform', 16);
            // Pruned on the sender's say-so (FCM answers UNREGISTERED for a dead install),
            // but a stale row that never gets sent to would otherwise live forever.
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
