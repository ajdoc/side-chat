<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Deletes guest accounts whose time is up.
 *
 * A guest is made for one meeting and is litter afterwards — an account with a member row, a
 * token and a name that would otherwise sit in a group chat's roster forever. The middleware
 * already refuses an expired one on sight, so this is housekeeping rather than a lock: the
 * difference between "cannot be used" and "no longer exists".
 *
 * ## It retires them; it does not delete them
 *
 * Deleting the row was the first instinct and it is wrong twice over, because two things cascade
 * off `users`:
 *
 *  - **`messages.user_id`** — deleting a guest cuts their side out of a conversation the other
 *    people in it are still entitled to read.
 *  - **`meeting_joins.user_id`** — the audit of who was admitted, which exists precisely to
 *    outlive the meeting. An account that deleted its own audit trail on the way out would make
 *    "who got in" unanswerable, which is the one question guests were always going to raise.
 *
 * So what has to stop existing is the **credential**, not the record: tokens revoked, seat in the
 * conversation given up, account left standing as the authorship it already is. A retired guest
 * can't sign in, can't be added anywhere, and shows up in the timeline and the audit exactly as
 * they did — which is the honest end state for somebody who was in a meeting once.
 */
class PruneGuests extends Command
{
    protected $signature = 'guests:prune';

    protected $description = 'Retire guest accounts whose meeting is long over.';

    public function handle(): int
    {
        // A grace period past the stamp, so a call that ran over doesn't have its guests
        // deleted out from under it while somebody is still talking.
        $cutoff = now()->subHours(6);

        $guests = User::query()
            ->where('is_guest', true)
            ->whereNotNull('guest_expires_at')
            ->where('guest_expires_at', '<', $cutoff)
            ->limit(500)
            ->get();

        $retired = 0;

        foreach ($guests as $guest) {
            // Already done — the query can't tell a retired guest from a live one, and asking
            // per row is cheaper than a join over every membership.
            if ($guest->tokens()->doesntExist() && $guest->conversations()->doesntExist()) {
                continue;
            }

            $guest->tokens()->delete();
            $guest->conversations()->detach();
            $retired++;
        }

        $this->info("Retired {$retired} guest account(s).");

        return self::SUCCESS;
    }
}
