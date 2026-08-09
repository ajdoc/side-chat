<?php

use App\Models\DeviceKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-use public keys, handed out once each and then gone.
 *
 * These are what give a new session forward secrecy. A conversation started with one is
 * unreadable even to someone who later seizes the device and takes every long-term key on
 * it, because the piece of the derivation that mattered was deleted the moment it was
 * claimed. That deletion is the entire feature, which is why {@see OneTimePrekey} is claimed
 * inside a transaction and why nothing here is ever updated in place.
 *
 * They run out. A popular account can have its stock drained faster than its client
 * refills — by ordinary traffic, or by somebody claiming them deliberately — and the system
 * has to keep working when it happens. It does: the session falls back to the signed prekey
 * alone, which is secure against everything except that later-compromise case. Degrading is
 * correct here; refusing to start the conversation would not be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('one_time_prekeys', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(DeviceKey::class)->constrained()->cascadeOnDelete();

            // Client-generated, and the handle the recipient uses to find the matching
            // private half in its own store. Unique per device, not globally.
            $table->string('prekey_id', 64);
            $table->text('public_key');

            $table->timestamps();

            $table->unique(['device_key_id', 'prekey_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('one_time_prekeys');
    }
};
