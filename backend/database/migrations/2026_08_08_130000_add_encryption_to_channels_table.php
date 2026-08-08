<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether messages sent here are encrypted before they leave the sender's device.
 *
 * Off for everything that exists and everything that will be created — DMs, group chats and
 * server channels alike. A false here means the channel behaves exactly as it always has,
 * and no row in `messages` is touched by this migration.
 *
 * It lives on `channels` rather than on `conversations` because a chat's messages live in
 * its single channel, and because servers and discussions are channels too: one column
 * covers all three surfaces, and everything downstream asks the channel.
 *
 * `encryption_epoch` is what makes the toggle *reversible*. Turning encryption on increments
 * it and starts a fresh sender key; turning it off leaves it where it is and goes back to
 * writing plaintext. Each message records the epoch it was written under (see the messages
 * migration), so a channel's timeline is a sequence of eras — plaintext, then encrypted,
 * then plaintext again — and every feature that reads message bodies decides per message
 * rather than per channel.
 *
 * What the epoch buys beyond a bare boolean:
 *
 *  - Turning encryption off and on again does **not** resurrect the old sender key, so a
 *    member removed during the gap can't read what was said after they left.
 *  - A member who joins mid-way is given sender keys from their joining epoch forward, which
 *    is precisely the "encrypted before you joined" case the client has to draw.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->boolean('encrypted')->default(false)->after('is_private');
            $table->unsignedInteger('encryption_epoch')->default(0)->after('encrypted');
            // Who flipped it last, and when. The timeline gets a system notice too, but that
            // is furniture a member could scroll past — this is the record.
            $table->foreignIdFor(User::class, 'encryption_toggled_by')->nullable()->after('encryption_epoch')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('encryption_toggled_at')->nullable()->after('encryption_toggled_by');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('encryption_toggled_by');
            $table->dropColumn(['encrypted', 'encryption_epoch', 'encryption_toggled_at']);
        });
    }
};
