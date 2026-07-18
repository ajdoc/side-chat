<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user Spotify link, for real listen-along playback.
 *
 * A user optionally connects their own Spotify account (OAuth) so the music widget can
 * play the *actual* track through Spotify's Web Playback SDK instead of a YouTube match —
 * but only if they're Premium (the SDK plays nothing for free accounts). `product` records
 * that so the client knows whether to use the Spotify engine or fall back to YouTube.
 *
 * The tokens are third-party OAuth credentials, so they're stored encrypted (see the User
 * model casts). `refresh_token` outlives the short-lived `access_token`, which is minted
 * fresh for the SDK on demand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('spotify_id')->nullable()->after('provider_id');
            $table->text('spotify_access_token')->nullable();
            $table->text('spotify_refresh_token')->nullable();
            $table->timestamp('spotify_token_expires_at')->nullable();
            // 'premium' | 'free' | 'open' — only 'premium' can drive the Web Playback SDK.
            $table->string('spotify_product')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'spotify_id',
                'spotify_access_token',
                'spotify_refresh_token',
                'spotify_token_expires_at',
                'spotify_product',
            ]);
        });
    }
};
