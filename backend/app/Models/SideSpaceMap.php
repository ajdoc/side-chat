<?php

namespace App\Models;

use App\Support\SideSpace\Decorations;
use App\Support\SideSpace\Tiles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Side Space channel's map: the room people walk around in.
 *
 * The geometry lives as a grid of characters (see the migration) and the questions anybody
 * actually asks of it are the three below — is this tile solid, which zone is this tile in, and
 * where do I put somebody who has no remembered position. They're answered here as well as in
 * the browser's engine (lib/spaceMapEngine.ts), deliberately: the client needs them at 60fps
 * without a round trip, and the server needs them to validate a saved map and to hand a
 * newcomer a legal spawn. Same rules, two places, because neither can be the other's.
 *
 * Two layers make a room: the grid of ground ({@see Tiles}) and the furniture standing on it
 * ({@see Decorations}). Both answer to "can somebody stand here", which is why {@see isWalkable}
 * consults them together and nothing else in the codebase asks about tiles alone.
 */
class SideSpaceMap extends Model
{
    /** @use HasFactory<\Database\Factories\SideSpaceMapFactory> */
    use HasFactory;

    /**
     * The three tiles that predate the overworld ones, kept as constants because the seeder,
     * the tests and half the room's callers name them. Everything else lives in {@see Tiles}.
     */
    public const FLOOR = Tiles::FLOOR;

    public const WALL = Tiles::WALL;

    public const VOID = Tiles::VOID;

    /** Every character a tile row may contain. Enforced when a map is saved. */
    public const TILE_CHARS = Tiles::ALL;

    /**
     * The grid may not be smaller than a room or bigger than one screen's worth of walking.
     * The ceiling is what stops a saved map being an arbitrarily large JSON blob every client
     * has to parse and draw.
     */
    public const MIN_SIZE = 8;

    public const MAX_SIZE = 80;

    protected $fillable = [
        'channel_id',
        'name',
        'width',
        'height',
        'tiles',
        'zones',
        'objects',
        'spawn',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'tiles' => 'array',
            'zones' => 'array',
            'objects' => 'array',
            'spawn' => 'array',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** Who is responsible for each of this map's rooms. Only owned zones have a row. */
    public function rooms(): HasMany
    {
        return $this->hasMany(SideSpaceRoom::class);
    }

    /** The locked doors. A door with no row here is one anybody may walk through. */
    public function locks(): HasMany
    {
        return $this->hasMany(SideSpaceLock::class);
    }

    /** Who last saved it. Null once they've left the server. */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** The character at a tile, or a wall for anything off the edge of the map. */
    public function tileAt(int $x, int $y): string
    {
        return $this->tiles[$y][$x] ?? self::WALL;
    }

    /**
     * Can somebody stand here?
     *
     * Off-map counts as solid, so the edge needs no special case. Furniture counts too: a desk
     * is as much a wall as a wall is, and a room where you can walk through the couch is a room
     * where the couch may as well not be there.
     */
    public function isWalkable(int $x, int $y): bool
    {
        return Tiles::isWalkable($this->tileAt($x, $y))
            && ! Decorations::blocks($this->objects ?? [], $x, $y);
    }

    /**
     * The decoration on a tile, or null. Used to answer "what am I standing next to" when
     * somebody presses E.
     *
     * @return array<string, mixed>|null
     */
    public function objectAt(int $x, int $y): ?array
    {
        foreach ($this->objects ?? [] as $object) {
            $kind = Decorations::find((string) ($object['kind'] ?? ''));

            if ($kind !== null && Decorations::covers($object, $kind, $x, $y)) {
                return $object;
            }
        }

        return null;
    }

    /**
     * The zone containing a tile, or null out in the open.
     *
     * First match wins — overlapping zones are not a thing the editor can draw, and if one ever
     * arrived through the API the answer still has to be single-valued for proximity to mean
     * anything.
     *
     * @return array<string, mixed>|null
     */
    public function zoneAt(int $x, int $y): ?array
    {
        foreach ($this->zones ?? [] as $zone) {
            if ($x >= $zone['x'] && $x < $zone['x'] + $zone['w']
                && $y >= $zone['y'] && $y < $zone['y'] + $zone['h']) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * Where somebody with no remembered position walks in.
     *
     * Falls back to *any* walkable tile if the stored spawn has since been painted over — a map
     * you can't be placed on is worse than one you enter in the wrong corner, and the editor's
     * validation can't retroactively fix a map saved before a rule existed.
     *
     * @return array{x: int, y: int}
     */
    public function spawnPoint(): array
    {
        $spawn = $this->spawn ?? [];

        if (isset($spawn['x'], $spawn['y']) && $this->isWalkable((int) $spawn['x'], (int) $spawn['y'])) {
            return ['x' => (int) $spawn['x'], 'y' => (int) $spawn['y']];
        }

        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                if ($this->isWalkable($x, $y)) {
                    return ['x' => $x, 'y' => $y];
                }
            }
        }

        return ['x' => 0, 'y' => 0];
    }
}
