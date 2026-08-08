<?php

namespace App\Support\SideSpace;

use App\Http\Controllers\SideSpaceController;

/**
 * The things you can put *in* a room.
 *
 * Tiles are the ground; these are everything standing on it. A decoration is stored as nothing
 * but a kind and a position — `{"id": "d-k3f", "kind": "speaker", "x": 12, "y": 5}` — and every
 * other property (how big it is, whether you can walk through it, whether pressing E on it does
 * anything) is looked up in this catalogue.
 *
 * That split is the whole design. A client that could send its own footprint could send a
 * 40×40 painting; a client that could send its own `interact` could point a plant pot at
 * somebody else's widget. So the payload carries the two things only the author knows, and the
 * server owns the rest. The browser has the same catalogue (`lib/spaceDecor.ts`) for drawing and
 * for the "Press E" prompt, in exactly the way the collision grid is mirrored — same data, two
 * places, because neither side can ask the other at the speed it needs the answer.
 *
 * ## Interactive furniture
 *
 * `interact` names an existing **Side Desk app** ({@see DeskApps}), and that is the entire
 * mechanism: walk up to the speaker, press E, and the room asks the server for this channel's
 * music widget — the same one `m!` would have made, shared by everybody — and floats it. The
 * furniture is a *doorway* to a thing the app already has, not a new thing. Which is why a room
 * can't invent a behaviour: it can only point at an app this server already knows how to open.
 *
 * Both families of app are reachable, and the difference is only in what comes back:
 *
 *   - a **widget app** (music, kanban, a game) resolves the channel's widget and floats that.
 *   - a **surface app** (the board, the notes, the calendar, the doc shelf) has no widget row;
 *     the room floats the app itself, which is the window the `a!board` command opens. So the
 *     whiteboard standing in the corner of a room and the Board tab on the Side Desk are one
 *     board, and always were — see {@see SideSpaceController::interact}.
 *
 * ## Which way it's turned
 *
 * A piece also stores a `facing`, and a quarter turn swaps its footprint: a 2×1 desk turned to
 * face right occupies 1×2 tiles. Everything that asks "what does this cover" — collision,
 * placement, the overlap check — goes through {@see self::size()} for that reason, because a
 * rotated desk that still blocked its old squares would be furniture you could walk through on
 * one side and bump into thin air on the other.
 */
final class Decorations
{
    /** Sits on the floor, and may block it. */
    public const MOUNT_FLOOR = 'floor';

    /** Hangs on a wall — has to be *on* a solid tile, and never blocks anything. */
    public const MOUNT_WALL = 'wall';

    /**
     * How many of one room's worth of furniture is enough.
     *
     * It was 120, which was a rendering budget: everything that asked about furniture — collision,
     * the "press E" reach, the draw loop — walked the whole list, so the cost of a room was its
     * furniture times how often anybody asked. A floor of an office is a real thing people build
     * here and 120 pieces doesn't furnish one.
     *
     * So the scans became lookups on both sides — {@see self::solidTiles()} here, the tile and row
     * index in `lib/spaceDecor.ts` there — and the ceiling is now about the room rather than the
     * renderer: 1000 pieces on the largest grid we allow ({@see SideSpaceMap::MAX_SIZE}, 6,400
     * tiles) is a room about a sixth furniture, which is a *full* office and not a wall of desks.
     * It also keeps a saved map to a size that's unremarkable to store, send and parse.
     */
    public const MAX_PER_MAP = 1000;

    /**
     * The four ways a piece can be turned, in quarter turns clockwise from the front view the
     * sprite is drawn in. `down` is what every piece placed before rotation existed stores
     * implicitly — the default is the old behaviour exactly.
     */
    public const FACINGS = ['down', 'left', 'up', 'right'];

    /** Quarter turns per facing. Odd turns swap a piece's footprint; see {@see self::size()}. */
    private const TURNS = ['down' => 0, 'left' => 1, 'up' => 2, 'right' => 3];

    /**
     * The catalogue, built once.
     *
     * It's a constant expressed as a literal, and {@see self::find()} is asked for one entry per
     * piece of furniture per collision query — rebuilding the whole table each time was free at a
     * hundred pieces and is not at a thousand. Nothing mutates it, so a static holds for the
     * process.
     *
     * @var array<string, array{label: string, w: int, h: int, solid: bool, mount: string, interact: string|null, verb: string|null, door: bool}>|null
     */
    private static ?array $catalogue = null;

    /**
     * Every kind, keyed by the value a saved map may use.
     *
     * @return array<string, array{label: string, w: int, h: int, solid: bool, mount: string, interact: string|null, verb: string|null, door: bool}>
     */
    public static function all(): array
    {
        return self::$catalogue ??= self::catalogue();
    }

    /** Built once per process — see {@see self::$catalogue}. */
    private static function catalogue(): array
    {
        return [
            // --- doors ---
            // Solid, like a wall, and that is the *stored* truth about them: a door's opening is
            // a moment, not a property, and nothing here knows where anybody is standing. See
            // the note on `door` in self::kind().
            'door' => self::kind('Door', door: true),
            'gate' => self::kind('Gate', w: 2, door: true),
            // --- interactive: furniture that opens something ---
            'speaker' => self::kind('Speaker', interact: 'music', verb: 'Put something on'),
            'tv' => self::kind('TV', w: 2, interact: 'video', verb: 'Watch something'),
            'computer' => self::kind('Computer', interact: 'kanban', verb: 'Check the board'),
            'arcade' => self::kind('Arcade cabinet', interact: 'shooter', verb: 'Play'),
            'racer' => self::kind('Racing cabinet', interact: 'racing', verb: 'Race'),
            'easel' => self::kind('Easel', interact: 'skribbl', verb: 'Draw'),
            'noticeboard' => self::kind('Notice board', mount: self::MOUNT_WALL, interact: 'poll', verb: 'Read the board'),

            // --- interactive: the Side Desk's own apps, standing in the room ---
            // A surface app has no widget behind it, so pressing E floats the app itself. The
            // whiteboard in the corner *is* the Board tab; there was only ever one of them.
            'whiteboard' => self::kind('Whiteboard', w: 2, interact: 'board', verb: 'Draw on it'),
            'lectern' => self::kind('Lectern', interact: 'notes', verb: 'Read the notes'),
            'planner' => self::kind('Wall planner', mount: self::MOUNT_WALL, interact: 'calendar', verb: 'Check the schedule'),
            'filecabinet' => self::kind('Filing cabinet', interact: 'docs', verb: 'Look through the files'),

            // --- furniture ---
            'desk' => self::kind('Desk', w: 2),
            'couch' => self::kind('Couch', w: 2),
            'bench' => self::kind('Bench', w: 2),
            'chair' => self::kind('Office chair', solid: false),
            'stool' => self::kind('Stool', solid: false),
            // Walk-on-able like the other single seats. Two tiles square, so that a person
            // sitting on it doesn't cover the thing they're sitting on — whether you can *sit*
            // on it at all is the browser's business, see the note on `seat` in lib/spaceDecor.ts.
            'throne' => self::kind('Iron throne', w: 2, h: 2, solid: false),
            'bookshelf' => self::kind('Bookshelf'),
            'cabinet' => self::kind('Cabinet'),
            'fridge' => self::kind('Fridge'),
            'watercooler' => self::kind('Water cooler'),
            'lamp' => self::kind('Floor lamp'),
            'plant' => self::kind('Potted plant'),
            // Soft, and therefore not solid: a toy on the floor is stepped over, not walked round.
            // One kind per outfit, because an object stores nothing but a kind and a place.
            'plush' => self::kind('Espurr plush', solid: false),
            'plush_vessel' => self::kind('Espurr Vessel plush', solid: false),
            'plush_pickachu' => self::kind('Espurr Pikachu plush', solid: false),
            'plush_gundam' => self::kind('Espurr Wing plush', solid: false),
            'plush_cubone' => self::kind('Cubone Vessel plush', solid: false),
            'plush_tanjiro' => self::kind('Espurr Tanjiro plush', solid: false),
            'crate' => self::kind('Crate'),
            'barrel' => self::kind('Barrel'),
            'campfire' => self::kind('Campfire'),

            // --- the gym set: what turns a room into an arena ---
            'pillar' => self::kind('Pillar'),
            'statue' => self::kind('Statue'),
            'torch' => self::kind('Torch'),
            'boulder' => self::kind('Boulder'),

            // --- flat things ---
            'rug' => self::kind('Rug', w: 2, h: 2, solid: false),
            'mat' => self::kind('Door mat', solid: false),

            // --- on the wall ---
            'painting' => self::kind('Painting', mount: self::MOUNT_WALL),
            'poster' => self::kind('Poster', mount: self::MOUNT_WALL),
            'window' => self::kind('Window', mount: self::MOUNT_WALL),
            'clock' => self::kind('Clock', mount: self::MOUNT_WALL),
            'shelf' => self::kind('Wall shelf', mount: self::MOUNT_WALL),

        ];
    }

    /**
     * One entry, with the defaults spelled out once instead of thirty times.
     *
     * Wall-mounted things are never solid — the wall they hang on already is, and a painting
     * that blocked the tile in front of it would fence off the room it decorates.
     *
     * `interact` is a {@see DeskApps} id; nothing validates that here, because this list *is*
     * the definition. What it must stay true to is that the app exists — an id no client can
     * render is furniture that answers E with a window that never opens.
     *
     * @return array{label: string, w: int, h: int, solid: bool, mount: string, interact: string|null, verb: string|null}
     */
    private static function kind(
        string $label,
        int $w = 1,
        int $h = 1,
        bool $solid = true,
        string $mount = self::MOUNT_FLOOR,
        ?string $interact = null,
        ?string $verb = null,
        bool $door = false,
    ): array {
        return [
            'label' => $label,
            'w' => $w,
            'h' => $h,
            'solid' => $mount === self::MOUNT_WALL ? false : $solid,
            'mount' => $mount,
            'interact' => $interact,
            'verb' => $verb,
            /*
             * A door opens by itself when somebody who may pass walks up to it, which makes it
             * the one piece of furniture whose solidity is a *function of the moment* rather
             * than a fact about the kind.
             *
             * The server keeps calling it solid anyway, and that is not a compromise. Everything
             * the server judges is about the room at rest — is spawn somewhere you can stand,
             * does this zone contain a standable tile, does that couch overlap anything — and
             * for every one of those questions the honest answer treats a door as shut. Nobody
             * is standing in a map being validated. Only the browser, which knows where everyone
             * is this frame, opens them; see lib/spaceDoors.ts.
             */
            'door' => $door,
        ];
    }

    /** Is this kind a door — something that opens rather than something you walk round? */
    public static function isDoor(string $kind): bool
    {
        return self::find($kind)['door'] ?? false;
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * One kind, or null if there's no such furniture.
     *
     * @return array{label: string, w: int, h: int, solid: bool, mount: string, interact: string|null, verb: string|null}|null
     */
    public static function find(string $kind): ?array
    {
        return self::all()[$kind] ?? null;
    }

    /**
     * Everything wrong with a set of furniture, against a given grid — the shared rulebook.
     *
     * Extracted here rather than left in the owner's map request because a *member* decorating a
     * room ({@see SideSpaceController::objects}) sends furniture alone and
     * has it checked against the map's *stored* tiles, while the owner sends furniture and tiles
     * together and has it checked against the payload. Two callers, one rulebook: a painting
     * hangs on a wall, a couch stands on the floor, nothing runs off the map, and two solid
     * things never share a tile. A rug under a desk is fine and rather the point.
     *
     * Returns `[$index => $message]` so each caller can turn a complaint into whatever error
     * shape its validator wants, keyed to the offending piece.
     *
     * @param  array<int, array<string, mixed>>  $objects
     * @param  array<int, string>  $tiles
     * @return array<int, string>
     */
    public static function problems(array $objects, array $tiles, int $width, int $height): array
    {
        $errors = [];
        $taken = [];
        $seen = [];

        foreach ($objects as $i => $object) {
            $kind = self::find((string) ($object['kind'] ?? ''));

            if ($kind === null) {
                $errors[$i] = 'That is not a kind of furniture we know.';

                continue;
            }

            // Keyed rather than a list scanned with in_array, which was a comparison per piece
            // per piece — the one quadratic left in a rulebook that now runs over a thousand.
            $id = (string) ($object['id'] ?? '');
            if (isset($seen[$id])) {
                $errors[$i] = 'Two pieces of furniture share an id.';

                continue;
            }
            $seen[$id] = true;

            $ox = (int) ($object['x'] ?? 0);
            $oy = (int) ($object['y'] ?? 0);
            // As placed, not as catalogued: a turned desk is 1×2 where an unturned one is 2×1,
            // and it's the turned one that has to fit.
            [$w, $h] = self::size($object, $kind);

            if ($ox + $w > $width || $oy + $h > $height) {
                $errors[$i] = "The {$kind['label']} runs off the map.";

                continue;
            }

            foreach (self::footprint($object, $kind) as [$x, $y]) {
                $tile = $tiles[$y][$x] ?? Tiles::VOID;
                $onWall = $kind['mount'] === self::MOUNT_WALL;

                if ($onWall && Tiles::isWalkable($tile)) {
                    $errors[$i] = "The {$kind['label']} has to hang on a wall.";

                    break;
                }

                if (! $onWall && ! Tiles::isWalkable($tile)) {
                    $errors[$i] = "The {$kind['label']} has to stand on the floor.";

                    break;
                }

                if (! $kind['solid']) {
                    continue;
                }

                if (isset($taken["$x,$y"])) {
                    $errors[$i] = "The {$kind['label']} overlaps something else.";

                    break;
                }

                $taken["$x,$y"] = true;
            }
        }

        return $errors;
    }

    /**
     * Every tile something solid stands on, as a `"x,y" => true` set.
     *
     * The same question {@see self::blocks()} answers, asked once for the whole room instead of
     * once per tile. Anything that sweeps an area — finding a spawn, checking a zone has
     * somewhere to stand in it, an Among Us round placing bodies — was walking the furniture list
     * for every square it looked at, which is a room's area times its furniture and grows the
     * wrong way in exactly the big rooms this is for. Build this once, then ask it.
     *
     * @param  array<int, array<string, mixed>>  $objects
     * @return array<string, true>
     */
    public static function solidTiles(array $objects): array
    {
        $solid = [];

        foreach ($objects as $object) {
            $kind = self::find((string) ($object['kind'] ?? ''));

            if ($kind === null || ! $kind['solid']) {
                continue;
            }

            foreach (self::footprint($object, $kind) as [$x, $y]) {
                $solid["$x,$y"] = true;
            }
        }

        return $solid;
    }

    /**
     * Does anything solid stand on this tile?
     *
     * Asked by the collision check and by every rule that has to know whether somewhere is
     * standable — spawning, zone validation, placing more furniture. A decoration occupies the
     * rectangle its catalogue entry declares, anchored at its stored top-left corner.
     *
     * A scan, because it's the right shape for a single question. Anything asking about more than
     * a handful of tiles should hold a {@see self::solidTiles()} set instead.
     *
     * @param  array<int, array<string, mixed>>  $objects
     */
    public static function blocks(array $objects, int $x, int $y): bool
    {
        foreach ($objects as $object) {
            $kind = self::find((string) ($object['kind'] ?? ''));

            if ($kind === null || ! $kind['solid']) {
                continue;
            }

            if (self::covers($object, $kind, $x, $y)) {
                return true;
            }
        }

        return false;
    }

    /**
     * How much floor a piece takes up *as placed* — its catalogue size, turned.
     *
     * The one place the quarter turn is applied. A piece facing left or right has been turned
     * an odd number of quarters, so its width and height trade places; facing up is a half turn,
     * which changes which way it looks and nothing about the space it needs.
     *
     * @param  array<string, mixed>  $object
     * @param  array{w: int, h: int}  $kind
     * @return array{0: int, 1: int}
     */
    public static function size(array $object, array $kind): array
    {
        $turns = self::TURNS[(string) ($object['facing'] ?? 'down')] ?? 0;

        return $turns % 2 === 1 ? [$kind['h'], $kind['w']] : [$kind['w'], $kind['h']];
    }

    /**
     * Every tile a decoration sits on, solid or not — what the "two things in the same place"
     * check compares.
     *
     * @param  array<string, mixed>  $object
     * @param  array{w: int, h: int}  $kind
     * @return array<int, array{0: int, 1: int}>
     */
    public static function footprint(array $object, array $kind): array
    {
        $ox = (int) ($object['x'] ?? 0);
        $oy = (int) ($object['y'] ?? 0);
        [$w, $h] = self::size($object, $kind);
        $tiles = [];

        for ($y = $oy; $y < $oy + $h; $y++) {
            for ($x = $ox; $x < $ox + $w; $x++) {
                $tiles[] = [$x, $y];
            }
        }

        return $tiles;
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array{w: int, h: int}  $kind
     */
    public static function covers(array $object, array $kind, int $x, int $y): bool
    {
        $ox = (int) ($object['x'] ?? 0);
        $oy = (int) ($object['y'] ?? 0);
        [$w, $h] = self::size($object, $kind);

        return $x >= $ox && $x < $ox + $w && $y >= $oy && $y < $oy + $h;
    }
}
