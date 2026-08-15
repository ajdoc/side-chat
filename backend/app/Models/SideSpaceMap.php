<?php

namespace App\Models;

use App\Support\SideSpace\Decorations;
use App\Support\SideSpace\Tiles;
use Database\Factories\SideSpaceMapFactory;
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
    /** @use HasFactory<SideSpaceMapFactory> */
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

    /**
     * The largest a map may be, each way.
     *
     * 80 originally, then 128 when maps became things you *join together* — a 30-wide office
     * extended with the 64-wide New York artwork is 94 across, and under 80 the city arrived with
     * Central Park cropped off. 256 now, for the same reason one step further out: several of
     * these artwork maps side by side is the shape people are actually building.
     *
     * ## What the number costs
     *
     * The grid travels as one document on every load, and it is the only thing here that grows
     * with the *square* of this constant. At 256 that is 65,536 characters — around 64KB of
     * tiles, against 16KB at 128. Sent once per load, gzipped in transit, and small beside the
     * artwork a backdrop map already downloads.
     *
     * Everything that reads the grid was checked rather than assumed. Drawing is culled to the
     * camera, so it costs what is on screen and not what is in the map. Collision is a lookup.
     * The one thing that genuinely did scale badly was the minimap, which repainted every tile
     * twelve times a second — that now caches its ground layer and repaints only when the map
     * changes; see SideSpaceMiniMap.
     *
     * ## What is now the binding limit
     *
     * Furniture, not size. {@see Decorations::MAX_PER_MAP} allows 1000 pieces, which was a sixth
     * of an 80x80 room and is one and a half percent of a 256x256 one. A map this large will run
     * out of things to put in it long before it runs out of floor.
     */
    public const MAX_SIZE = 256;

    /**
     * This map's furniture as a `"x,y" => true` set, built on first use.
     *
     * {@see self::spawnPoint()} and the games that scatter things about ask
     * {@see self::isWalkable()} for every square of the grid, so the alternative is the furniture
     * list walked 6,400 times. Kept in step by {@see self::setAttribute()}.
     *
     * @var array<string, true>|null
     */
    private ?array $solid = null;

    /**
     * A map with no slug is the channel's way in.
     *
     * Mirrors the column's default rather than relying on it, so a map that has just been
     * created knows which one it is without being read back — `$channel->spaceMap()->create()`
     * is how every Side Space in the app is born, and it names no slug.
     */
    protected $attributes = [
        'slug' => self::MAIN,
    ];

    protected $fillable = [
        'channel_id',
        'slug',
        'name',
        'width',
        'height',
        'tiles',
        'zones',
        'objects',
        'spawn',
        'projection',
        'backdrops',
        'portals',
        'screens',
        'exhibits',
        'updated_by',
    ];

    /**
     * The ways a room can be drawn — mirrored in the client's lib/spaceProjection.ts.
     *
     * `flat` looks straight down at the grid; `iso` turns it 45° and halves its height, which is
     * the projection hand-drawn room art is normally authored in. Nothing on the server renders
     * anything, so this is only ever passed through — but it's validated here so a map can't be
     * saved claiming a projection no client knows how to draw, which would be a room that opens
     * to a blank canvas.
     */
    public const PROJECTIONS = ['flat', 'iso'];

    /**
     * How many pieces of backdrop artwork one map may carry.
     *
     * Low on purpose. Each placement is a full-size image every browser in the room downloads
     * before the map is drawable, and the feature exists so a map can be a hand-built room *and*
     * a city — a handful of districts, not a mosaic of forty.
     */
    public const MAX_BACKDROPS = 8;

    /**
     * How many doorways one map may have.
     *
     * Generous, because a portal costs almost nothing — a rectangle and a destination — and a
     * city map with a station in every district is a reasonable thing to build. The bound exists
     * so the walk loop's "am I standing in one" check stays a short scan rather than something
     * worth indexing.
     */
    public const MAX_PORTALS = 40;

    /**
     * How a doorway is taken: by walking into it, or by pressing E while stood in it.
     *
     * A property of the *doorway* rather than a setting, because the right answer genuinely
     * differs per door. A wormhole between two halves of one island wants `walk` — it is scenery
     * you run through, and stopping to press a key at every one would make crossing the map a
     * chore. A door out of the room, or into another Side Space, wants `press`: it is a decision,
     * and one careless step should not end the conversation you were having.
     *
     * `walk` is the default, and absent reads as `walk`, because that is what every doorway built
     * before this existed did.
     */
    public const ACTIVATIONS = ['walk', 'press'];

    /**
     * How many screen surfaces one map may have.
     *
     * Small, and the reason is cost rather than taste: every surface is a `drawImage` of a live
     * video frame, every frame, and a video frame is the most expensive thing this renderer ever
     * paints. Four is a foyer and three auditoria, which is more cinema than anybody needs; a map
     * with forty would spend its frame budget painting the same picture forty times.
     */
    public const MAX_SCREENS = 4;

    /**
     * How many frames one map may hang.
     *
     * Generous, because a gallery is the point: the Met's artwork covers most of its walls, and a
     * museum that ran out of frames a third of the way round would be a worse room than one with
     * none. Each is a rectangle and, at most, one row and one image — and the images are fetched
     * only when somebody opens one, never with the map.
     */
    public const MAX_EXHIBITS = 120;

    /**
     * The map a Side Space opens to, and the only one that has to exist.
     *
     * Every other map on a channel is an *interior*: somewhere you can only arrive by walking
     * through a door, which is what lets it be built without a way in and be nowhere rather than
     * be broken. The main one is the way in, so it is never deleteable and never renameable —
     * a channel whose main map went missing is a room that opens to a blank canvas.
     */
    public const MAIN = 'main';

    /**
     * How many maps one Side Space may hold, the main one included.
     *
     * The cost of an interior is not what it does — nobody is standing in most of them at any
     * moment — but that the editor lists them, a portal target dropdown lists them, and the
     * duplicate-a-channel path copies every one. A dozen is a cinema with its screens, a house
     * with its rooms, a dungeon with its floors; past that the thing being built is a world, and
     * a world is what several channels are for.
     */
    public const MAX_PER_CHANNEL = 12;

    /**
     * What a map's slug may be: lowercase, digits and dashes.
     *
     * Narrow on purpose. A slug is written into every portal that points at the map and survives
     * being copied between channels, so it is an identifier people type and read in the editor
     * rather than a label they style — and the set of characters that are unambiguous in that
     * role is small.
     */
    public const SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /** Is this the map the channel opens to? */
    public function isMain(): bool
    {
        return $this->slug === self::MAIN;
    }

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'tiles' => 'array',
            'zones' => 'array',
            'objects' => 'array',
            'spawn' => 'array',
            'backdrops' => 'array',
            'portals' => 'array',
            'screens' => 'array',
            'exhibits' => 'array',
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

    /**
     * What is hanging in each frame. A frame with no row here is an empty one.
     *
     * The rectangles themselves are in the `exhibits` column, which any member may edit; these
     * are the pictures, which only staff may hang. See the exhibits migration for the split.
     */
    public function exhibitPieces(): HasMany
    {
        return $this->hasMany(SideSpaceExhibit::class);
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
        $this->solid ??= Decorations::solidTiles($this->objects ?? []);

        return Tiles::isWalkable($this->tileAt($x, $y))
            && ! isset($this->solid["$x,$y"]);
    }

    /**
     * Thrown away the moment the furniture changes, which is the whole reason this is here
     * rather than a local in each sweep: a map saved and then asked about again inside one
     * request would otherwise answer from the room it used to be.
     */
    public function setAttribute($key, $value)
    {
        if ($key === 'objects') {
            $this->solid = null;
        }

        return parent::setAttribute($key, $value);
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
