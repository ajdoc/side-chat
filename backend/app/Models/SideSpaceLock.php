<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A locked door.
 *
 * The row is the lock: a door with no row is open to anybody who walks up to it, and there is no
 * such thing as an unlocked lock. Removing a lock is deleting the row, which is why the endpoint
 * is a DELETE and not a flag.
 *
 * A lock can also carry a **password**: anybody who says it is let through for the next
 * {@see self::PASS_SECONDS} seconds and then has to say it again. It's the other half of what a
 * lock is for — letting in people you couldn't have named in advance. See the migration for why
 * that's a second column, and the later one for why it holds a clock rather than a list.
 *
 * `allowed` holds only the people who were *given* a key. Three more can always pass and are
 * resolved at read time rather than stored here — whoever set the lock, whoever owns the room,
 * and the server's owner. Copying them in would mean a room changing hands left its previous
 * owner with a key nobody could see, which is exactly the kind of lock nobody trusts.
 */
class SideSpaceLock extends Model
{
    /** @use HasFactory<\Database\Factories\SideSpaceLockFactory> */
    use HasFactory;

    protected $fillable = ['side_space_map_id', 'object_id', 'zone_id', 'created_by', 'allowed', 'password', 'passed'];

    /**
     * The phrase never leaves the server, in any form. `hidden` is belt and braces — nothing
     * serialises this model directly — but a lock's hash appearing in a map payload that every
     * browser in the room receives is exactly the accident worth making impossible.
     */
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'allowed' => 'array',
            'passed' => 'array',
            'password' => 'hashed',
        ];
    }

    /**
     * How long saying the password buys you, in seconds.
     *
     * Long enough to walk through the door you're standing at from wherever the dialog left you,
     * with room for a slow hand — and short enough that it is over before you could have done
     * anything else with it. Coming back out is a second crossing and costs the words again,
     * which is the difference between a password and a key.
     *
     * It is a *duration* rather than a single crossing because every browser in the room decides
     * for itself when a door opens, from the map it was sent (see lib/spaceDoors.ts). A deadline
     * is a fact they can all agree on without asking anybody; "has this person been through yet"
     * is one they would have to be told, and they would be told it late.
     */
    public const PASS_SECONDS = 15;

    /** Whether this door will open for somebody who knows the words. */
    public function hasPassword(): bool
    {
        return $this->password !== null && $this->password !== '';
    }

    /**
     * Who is currently through on a password, and until when.
     *
     * Expired rows are dropped on the way out rather than deleted on a schedule: nothing needs to
     * know about a lapsed pass, so the pruning that matters is the one at the point of reading.
     * The column is tidied whenever a new pass is granted, which is often enough.
     *
     * @return array<int, int>  user id => unix timestamp the pass runs out
     */
    public function activePasses(): array
    {
        $now = now()->getTimestamp();
        $passes = [];

        foreach ((array) ($this->passed ?? []) as $id => $until) {
            // Anything that isn't an id-keyed timestamp is the old list shape, which meant a
            // permanent key and no longer exists. Ignored rather than honoured — see the
            // migration.
            if (! is_numeric($id) || ! is_numeric($until) || (int) $until <= $now) {
                continue;
            }

            $passes[(int) $id] = (int) $until;
        }

        return $passes;
    }

    /** Let somebody through, starting now. Re-saying the password simply starts the clock again. */
    public function grantPass(int $userId): void
    {
        // Assigned into the pruned set rather than spread into it: these are integer keys, and
        // array spread renumbers those, which would hand the pass to whoever happened to be
        // first in the list.
        $passes = $this->activePasses();
        $passes[$userId] = now()->getTimestamp() + self::PASS_SECONDS;

        $this->update(['passed' => $passes]);
    }

    public function map(): BelongsTo
    {
        return $this->belongsTo(SideSpaceMap::class, 'side_space_map_id');
    }

    /** Who set it. Null once they've left the server — the lock stays shut. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
