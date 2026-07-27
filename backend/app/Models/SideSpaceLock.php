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
 * `allowed` holds only the people who were *given* a key. Three more can always pass and are
 * resolved at read time rather than stored here — whoever set the lock, whoever owns the room,
 * and the server's owner. Copying them in would mean a room changing hands left its previous
 * owner with a key nobody could see, which is exactly the kind of lock nobody trusts.
 */
class SideSpaceLock extends Model
{
    /** @use HasFactory<\Database\Factories\SideSpaceLockFactory> */
    use HasFactory;

    protected $fillable = ['side_space_map_id', 'object_id', 'zone_id', 'created_by', 'allowed'];

    protected function casts(): array
    {
        return ['allowed' => 'array'];
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
