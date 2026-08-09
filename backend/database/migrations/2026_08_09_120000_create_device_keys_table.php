<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A device's public keys: how anybody starts an encrypted conversation with it.
 *
 * Everything here is a *public* half. That is not an incidental property, it is the design:
 * the server's job in an end-to-end encrypted system is to be a directory, and a directory
 * that held private keys would make the encryption theatre. Nothing in this table would help
 * an attacker who took the database read a single message.
 *
 * A **device**, not a user. One person with a laptop and a phone is two rows, two identity
 * keys, and two sender chains in every channel they're in — because "my phone" and "my
 * laptop" really are different parties, and a key that moved between them would be a key
 * that could move anywhere. It is also what makes revocation meaningful: losing a phone
 * deletes one row rather than resetting an account.
 *
 * `signing_public` is separate from `identity_public` and both are stored, because the pair
 * do different jobs — one agrees on secrets, one proves authorship of a prekey. See the
 * client's `identity.ts` for why a single key doing both is worse than two doing one each.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();

            // Client-generated, and unique per account rather than globally: it is a name for
            // "this browser profile on this machine", minted once and reused for the life of
            // the install. It travels in every envelope, so it is short and opaque.
            $table->string('device_id', 64);

            // Base64. Raw P-256 points, 65 bytes each, so a modest string column is plenty —
            // but they are opaque to the server, which never parses them.
            $table->text('identity_public');
            $table->text('signing_public');

            // The medium-lived prekey and its signature. Rotated periodically by the client;
            // the signature is what stops the *server* substituting its own and reading
            // everything, so the two always move together.
            $table->text('signed_prekey');
            $table->text('prekey_signature');

            // Last time this device was heard from. A device silent for months is one whose
            // owner has probably lost it, and eventually a candidate for pruning — nothing
            // does that yet, but a directory with no notion of staleness can never start.
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            // One row per device per account, and the lookup every session start does.
            $table->unique(['user_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_keys');
    }
};
