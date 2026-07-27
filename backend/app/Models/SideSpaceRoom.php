<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's responsibility for one room.
 *
 * "Room owner" is not a new place in the world — it's a name against a zone the map already had.
 * A zone is bounded, named and sound-proof, which is everything a room needs to be before anyone
 * can be put in charge of one.
 *
 * The row is a *pair*, not a room: a room with three people in charge of it has three rows. That
 * is why there is no nullable owner here and no "unowned room" row — a room nobody owns is a room
 * with no rows, which is the same thing said once instead of twice.
 *
 * The row points at the zone by the id inside the map's JSON, so it can outlive the zone; see the
 * migration for why that's the safe direction. Nothing here assumes the zone still exists.
 */
class SideSpaceRoom extends Model
{
    /** @use HasFactory<\Database\Factories\SideSpaceRoomFactory> */
    use HasFactory;

    protected $fillable = ['side_space_map_id', 'zone_id', 'owner_id', 'assigned_by'];

    public function map(): BelongsTo
    {
        return $this->belongsTo(SideSpaceMap::class, 'side_space_map_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
