<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somebody's sender chains, wrapped under a passphrase only they know.
 *
 * The one table in this feature that gives an attacker something to work on, and it is worth
 * being blunt about why it exists anyway. Without it, clearing your browser data loses every
 * encrypted message you have ever received, permanently, with no warning and no way back.
 * That is the correct behaviour for a threat model where nothing may ever be recoverable, and
 * the wrong default for a chat app people use on more than one machine.
 *
 * What the server holds is ciphertext and the parameters needed to derive the key from a
 * passphrase it does not have. It cannot read the chains. What it *can* do is hand the blob
 * to whoever convinces it they are the account holder, and that is the real exposure: an
 * attacker who takes this table gets unlimited offline guesses. Hence PBKDF2 at 600,000
 * iterations client-side (see the frontend's `backup.ts`), and hence this being a choice
 * rather than a given — somebody who would rather lose their history than store this can opt
 * out and keep a recovery file instead, and then no row here exists at all.
 *
 * One row per account. A backup is a snapshot of everything, not an append-only log: keeping
 * old ones would mean keeping chains the person has since had every reason to think were gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('key_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->unique()->constrained()->cascadeOnDelete();

            // The wrapped chains. Opaque, and never parsed on this side.
            $table->text('blob');

            // How the wrapping key was derived, so a blob made years ago still opens after the
            // parameters have moved on. Stored as the client sent them and echoed back
            // untouched — the server has no opinion here, it is carrying a note.
            $table->string('kdf', 32);
            $table->unsignedInteger('iterations');

            // Shown in settings ("last backed up on…"), which is the only way somebody finds
            // out their client stopped syncing before they need the thing.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('key_backups');
    }
};
