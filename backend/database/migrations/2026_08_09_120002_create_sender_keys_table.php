<?php

use App\Models\Channel;
use App\Models\DeviceKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One sender's chain key, wrapped for one recipient device.
 *
 * The fan-out table, and the only one here that grows with use. A sender distributing to a
 * 25-person group where everybody has two devices writes 50 rows — once per epoch, not once
 * per message. That ratio is the whole argument for sender keys: after these rows exist,
 * every message in the channel costs one encryption and one row in `messages`, whatever the
 * size of the audience.
 *
 * `wrapped_key` is ciphertext the server cannot open. It is sealed under a secret derived
 * from an X3DH agreement between exactly two devices, so the server holds a pile of blobs
 * and no way to unwrap any of them — see the client's `identity.ts`.
 *
 * Keyed by epoch as well as channel because epochs are eras, and eras are how membership
 * changes are handled. Somebody removed from a channel keeps whatever rows they already
 * fetched — that is unavoidable, they had the key — but the next epoch's rows are simply
 * never written for them, and everything said afterwards is beyond reach. Old rows are kept
 * rather than deleted: a member who was there at the time is entitled to reread that era,
 * and on a new device they will need to fetch it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sender_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Channel::class)->constrained()->cascadeOnDelete();
            $table->unsignedInteger('epoch');

            // Who the chain belongs to, and who this copy is for. Both are devices, and they
            // are occasionally the same one — a person's laptop distributes to their own
            // phone like any other recipient, because it is one.
            $table->foreignIdFor(DeviceKey::class, 'sender_device_id')->constrained('device_keys')->cascadeOnDelete();
            $table->foreignIdFor(DeviceKey::class, 'recipient_device_id')->constrained('device_keys')->cascadeOnDelete();

            // The sealed chain key, plus the ephemeral public key the recipient needs to
            // reproduce the session secret, and which one-time prekey (if any) was consumed.
            $table->text('wrapped_key');
            $table->text('wrap_iv');
            $table->text('ephemeral_public');
            $table->string('prekey_id', 64)->nullable();

            $table->timestamps();

            // A sender distributes to a recipient once per era. A second attempt replaces the
            // first rather than adding to it — see EncryptionController::distribute.
            $table->unique(['channel_id', 'epoch', 'sender_device_id', 'recipient_device_id'], 'sender_keys_unique');
            // "Everything addressed to me in this channel" — the query a client makes when it
            // opens an encrypted timeline it hasn't read before.
            $table->index(['recipient_device_id', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_keys');
    }
};
