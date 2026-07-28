<?php

namespace App\Support\SideSpace;

use App\Models\SideSpaceLock;
use App\Models\SideSpaceMap;
use App\Models\User;

/**
 * Doors: which room one belongs to, who may lock it, and who may walk through it.
 *
 * The rules live here rather than in the controller because three different callers need the
 * same answers and must not disagree — the endpoint that sets a lock, the endpoint that lists
 * them, and the map resource that tells every browser in the room which doors will open for
 * whom. A door that the server thinks is locked and a browser thinks is open is a door people
 * walk through.
 *
 * ## Which room a door guards
 *
 * A door is *in a doorway*, which is a gap in a wall, which is very often the tile just outside
 * the room rather than inside it. So the question can't be answered by containment alone. It's
 * answered by reach: the zone under the door if there is one, otherwise the zone under any tile
 * the door touches. A door in the wall of exactly one room belongs to that room, which is the
 * case that matters and very nearly the only case there is.
 *
 * A door in open ground belongs to no room. Only the server's owner can lock one of those —
 * there is nobody else it could sensibly belong to.
 */
final class Doors
{
    /**
     * The id of the zone a door guards, or null for a door standing in the open.
     *
     * @param  array<string, mixed>  $door
     */
    public static function zoneFor(SideSpaceMap $map, array $door): ?string
    {
        $kind = Decorations::find((string) ($door['kind'] ?? ''));

        if ($kind === null) {
            return null;
        }

        $footprint = Decorations::footprint($door, $kind);

        // Under the door first. A door drawn inside a room's rectangle belongs to it outright,
        // and asking the neighbours as well could only find a different answer than the obvious
        // one.
        foreach ($footprint as [$x, $y]) {
            $zone = $map->zoneAt($x, $y);

            if ($zone !== null) {
                return (string) $zone['id'];
            }
        }

        // Otherwise whatever it opens onto — the doorway case, and the usual one.
        foreach ($footprint as [$x, $y]) {
            foreach ([[0, -1], [0, 1], [-1, 0], [1, 0]] as [$dx, $dy]) {
                $zone = $map->zoneAt($x + $dx, $y + $dy);

                if ($zone !== null) {
                    return (string) $zone['id'];
                }
            }
        }

        return null;
    }

    /** Every door on the map, keyed by its id. */
    public static function all(SideSpaceMap $map): array
    {
        $doors = [];

        foreach ($map->objects ?? [] as $object) {
            if (Decorations::isDoor((string) ($object['kind'] ?? ''))) {
                $doors[(string) ($object['id'] ?? '')] = $object;
            }
        }

        return $doors;
    }

    /**
     * Do they run this server — as owner, or as one of its admins? The one permission that
     * overrides every other here: staff hold the master key to every locked door.
     */
    public static function isServerOwner(SideSpaceMap $map, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $server = $map->loadMissing('channel.server')->channel?->server;

        return $server !== null && $server->isStaff($user);
    }

    /**
     * Everybody responsible for a room. Empty when nobody has been put in charge of one.
     *
     * A list rather than a name: a room can have several owners, which is the ordinary case for
     * anywhere a team works rather than a person.
     *
     * @return array<int, int>  user ids
     */
    public static function ownersOf(SideSpaceMap $map, ?string $zoneId): array
    {
        if ($zoneId === null) {
            return [];
        }

        return $map->loadMissing('rooms')->rooms
            ->where('zone_id', $zoneId)
            ->pluck('owner_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * May they lock and unlock the doors of this room?
     *
     * The server's owner may do it anywhere, including to doors in open ground and to rooms
     * somebody else is in charge of — it's their server. A room owner may do it to their own
     * room and nowhere else. Nobody else may do it at all.
     */
    public static function mayAdminister(SideSpaceMap $map, ?User $user, ?string $zoneId): bool
    {
        if ($user === null) {
            return false;
        }

        if (self::isServerOwner($map, $user)) {
            return true;
        }

        return $zoneId !== null && in_array($user->id, self::ownersOf($map, $zoneId), true);
    }

    /**
     * Everybody who may walk through a locked door.
     *
     * The explicit key-holders, plus two who never need one: whoever set the lock, and whoever is
     * responsible for the room it guards. Resolved here, every time, rather than frozen into the
     * row — a room changing hands has to take the old owner's key with it, and a lock whose
     * stored list still named them would be a lock nobody could reason about.
     *
     * ## The server's owner is not on this list
     *
     * They can *unlock* any door in the space ({@see mayAdminister}) — but that is an act, and it
     * is visible: the lock is gone afterwards, and whoever set it can see that it's gone. A
     * standing key is neither. It would mean the one person nobody can exclude walks through
     * every private room in the space silently, and a lock with an invisible exception in it is
     * not a lock anybody can rely on. Owning the server is authority over the *rules*, not a
     * passkey to the rooms.
     *
     * They do still hold a key to the locks they set themselves, as the creator — which is the
     * same rule everybody else gets, not a privilege.
     *
     * @return array<int, int>  user ids
     */
    public static function keyholders(SideSpaceMap $map, SideSpaceLock $lock): array
    {
        $ids = array_map('intval', $lock->allowed ?? []);

        if ($lock->created_by !== null) {
            $ids[] = (int) $lock->created_by;
        }

        foreach (self::ownersOf($map, $lock->zone_id) as $owner) {
            $ids[] = $owner;
        }

        return array_values(array_unique($ids));
    }
}
