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
 * A lock can also carry a **password**: anybody who enters it is remembered in `passed` and walks
 * through from then on. It's the other half of what a lock is for — letting in people you
 * couldn't have named in advance. See the migration for why that's a second list.
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

    /** Whether this door will open for somebody who knows the words. */
    public function hasPassword(): bool
    {
        return $this->password !== null && $this->password !== '';
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
