<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether *this* message's body is ciphertext.
 *
 * Per message, not per channel, and that is the whole point. Encryption is a toggle a
 * channel can be turned on and off, so a timeline is striped: plaintext runs and encrypted
 * runs interleaved, permanently. Anything that reads a body — search, unfurling, bots,
 * automations — has to ask each message rather than the channel it is in, because the
 * channel only knows what is true *now*.
 *
 * The consequence worth being explicit about: turning encryption off restores search and
 * bots for everything sent afterwards, and never for what was sent while it was on. The
 * server never had those keys and cannot acquire them later. There is no backfill, in
 * either direction, and a feature that pretended otherwise would be lying.
 *
 * `body` is unchanged and still holds the message — a base64 envelope when encrypted. No
 * new column, no type change, and nothing to migrate: every existing row is plaintext at
 * epoch 0, which is what the defaults say.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('encrypted')->default(false)->after('body');
            // Which key era this was written under. Null on plaintext — it isn't "epoch 0",
            // it's "no key was involved", and a reader that confuses the two would go looking
            // for a key that never existed.
            $table->unsignedInteger('epoch')->nullable()->after('encrypted');

            // Search excludes ciphertext on every query it runs, so the flag rides along with
            // the column the timeline is already keyed by.
            $table->index(['channel_id', 'encrypted']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['channel_id', 'encrypted']);
            $table->dropColumn(['encrypted', 'epoch']);
        });
    }
};
