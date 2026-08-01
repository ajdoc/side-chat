<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The inbound half of a bot: where to tell it things happened.
 *
 * Phase 1 gave bots a way to talk. Without this they can only ever talk *first* — a bot
 * that answers a question has no way of hearing one asked, short of polling the message
 * endpoint, which is a bad answer at any interval.
 *
 * Columns on `bots` rather than a `bot_subscriptions` table: a bot has one endpoint. A
 * second row per bot would only earn its keep if one bot fanned out to several URLs, which
 * nothing wants — and `events` as a JSON array covers "which of these do I care about"
 * without a join on every message sent.
 *
 * `webhook_secret` is the shared key the HMAC signature is computed with. Unlike the API
 * token it's stored *reversibly* (encrypted at rest, not hashed): both ends have to compute
 * the same signature, so we need the value back, which a hash can't give. That makes it the
 * more dangerous of the two secrets to leak from the database, hence the cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->string('webhook_url', 2048)->nullable()->after('description');
            $table->text('webhook_secret')->nullable()->after('webhook_url');
            // Which events to deliver. Null means "the default set" rather than "none" —
            // see Bot::subscribedEvents.
            $table->json('events')->nullable()->after('webhook_secret');
            // Consecutive failures. Reset by any success; at the ceiling the endpoint is
            // switched off rather than retried forever (see config/bots.php).
            $table->unsignedSmallInteger('webhook_failures')->default(0)->after('events');
            $table->timestamp('webhook_disabled_at')->nullable()->after('webhook_failures');
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn(['webhook_url', 'webhook_secret', 'events', 'webhook_failures', 'webhook_disabled_at']);
        });
    }
};
