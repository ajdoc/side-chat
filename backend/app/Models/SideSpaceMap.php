<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Side Space channel's map: the room people walk around in.
 *
 * The geometry lives as a grid of characters (see the migration) and the questions anybody
 * actually asks of it are the three below — is this tile solid, which zone is this tile in, and
 * where do I put somebody who has no remembered position. They're answered here as well as in
 * the browser's engine (lib/spaceMapEngine.ts), deliberately: the client needs them at 60fps
 * without a round trip, and the server needs them to validate a saved map and to hand a
 * newcomer a legal spawn. Same rules, two places, because neither can be the other's.
 */
class SideSpaceMap extends Model
{
    /** @use HasFactory<\Database\Factories\SideSpaceMapFactory> */
    use HasFactory;

    /** Walkable. Anything else — wall or void — is not. */
    public const FLOOR = '.';

    public const WALL = '#';

    public const VOID = ' ';

    /** Every character a tile row may contain. Enforced when a map is saved. */
    public const TILE_CHARS = self::FLOOR.self::WALL.self::VOID;

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
            'spawn' => 'array',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
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

    /** Can somebody stand here? Off-map counts as solid, so the edge needs no special case. */
    public function isWalkable(int $x, int $y): bool
    {
        return $this->tileAt($x, $y) === self::FLOOR;
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
