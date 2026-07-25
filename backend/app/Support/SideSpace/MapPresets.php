<?php

namespace App\Support\SideSpace;

/**
 * The rooms a Side Space can be born as.
 *
 * Kept on the server, not in the browser, because creating a channel has to *seed a map* — and
 * a client that supplied its own would be handing us geometry to trust. The creation page reads
 * this list to draw the picker; the create endpoint reads it to build the room. One source.
 *
 * A preset is a complete map: the same width/height/tiles/zones/objects/spawn a saved one has,
 * so seeding is a copy and nothing downstream can tell a preset room from an edited one. Which
 * is the point — you pick a starting point, not a template you're stuck inside.
 *
 * ## Why they're built rather than typed out
 *
 * Every row has to be exactly `width` characters, and a preset is the one map nobody can fix
 * from the editor when it's wrong — it's what everybody's room starts as. Hand-counting a
 * thirty-character string twenty times is precisely the sort of thing that is right until it
 * quietly isn't, so the rows are assembled with {@see fill} and {@see stamp} and are correct by
 * construction. It reads worse than a picture of a room and is wrong far less often.
 *
 * Tile characters live in {@see Tiles}; furniture kinds in {@see Decorations}.
 */
final class MapPresets
{
    /**
     * Every preset, keyed by the value the create endpoint accepts.
     *
     * @return array<string, array{
     *     label: string,
     *     description: string,
     *     name: string,
     *     width: int,
     *     height: int,
     *     tiles: array<int, string>,
     *     zones: array<int, array{id: string, name: string, kind: string, x: int, y: int, w: int, h: int}>,
     *     objects: array<int, array{id: string, kind: string, x: int, y: int}>,
     *     spawn: array{x: int, y: int}
     * }>
     */
    public static function all(): array
    {
        return [
            'office' => self::office(),
            'lounge' => self::lounge(),
            'park' => self::park(),
            'campfire' => self::campfire(),
            // The gyms: an arena crossed with an office, one per badge. See gym().
            'gym-cinnabar' => self::gymCinnabar(),
            'gym-celadon' => self::gymCeladon(),
            'gym-vermilion' => self::gymVermilion(),
            'gym-azalea' => self::gymAzalea(),
            'gym-olivine' => self::gymOlivine(),
            'gym-blackthorn' => self::gymBlackthorn(),
            'blank' => self::blank(),
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * One preset by key, or null if there's no such thing.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * An open floor with two glassed-in meeting rooms off it.
     *
     * The shape that makes proximity worth having: a big room where distance does the work, and
     * two sealed ones for when it shouldn't. Boards underfoot, carpet in the meeting rooms, and
     * enough furniture that walking across it feels like crossing an office rather than a plan
     * of one. 30×20.
     */
    private static function office(): array
    {
        $w = 30;
        $h = 20;
        $tiles = self::room($w, $h, Tiles::WOOD);

        // Two meeting rooms: a box of wall with a carpeted inside and a doorway in the south wall.
        foreach ([5, 17] as $x0) {
            self::stamp($tiles, $x0, 3, [
                '########',
                '#%%%%%%#',
                '#%%%%%%#',
                '#%%%%%%#',
                '###==###',
            ]);
        }

        // A back office behind a partition — the corner you go to when you want the room to
        // stop hearing you without being sealed off from it.
        self::stamp($tiles, 5, 12, [str_repeat(Tiles::WALL, 25)]);
        self::stamp($tiles, 5, 13, ['#', '#']);

        // A rug marks out the lounge end without walling it off.
        self::rect($tiles, 22, 15, 4, 3, Tiles::CARPET);

        return [
            'label' => 'Office',
            'description' => 'An open floor with two closed meeting rooms, desks and a lounge corner',
            'name' => 'Office',
            'width' => $w,
            'height' => $h,
            'tiles' => $tiles,
            'zones' => [
                ['id' => 'meet-a', 'name' => 'Meeting room A', 'kind' => 'private', 'x' => 6, 'y' => 4, 'w' => 6, 'h' => 3],
                ['id' => 'meet-b', 'name' => 'Meeting room B', 'kind' => 'private', 'x' => 18, 'y' => 4, 'w' => 6, 'h' => 3],
            ],
            'objects' => self::objects([
                // Meeting room A: a desk to sit around and a computer on it.
                ['desk', 7, 5], ['chair', 7, 4], ['chair', 9, 4], ['computer', 10, 5],
                // Meeting room B: the room you go to to watch something together.
                ['tv', 19, 4], ['couch', 19, 6], ['chair', 22, 5],
                // The open floor: desks along the middle, a speaker by the wall.
                ['desk', 8, 9], ['chair', 8, 10], ['computer', 9, 8],
                ['desk', 13, 9], ['chair', 13, 10], ['computer', 14, 8],
                ['desk', 18, 9], ['chair', 18, 10],
                ['speaker', 27, 8], ['watercooler', 27, 9],
                // The lounge corner.
                ['rug', 22, 15], ['couch', 22, 15], ['arcade', 25, 16], ['plant', 22, 17],
                // The back office.
                ['desk', 8, 13], ['chair', 8, 14], ['easel', 12, 13], ['bookshelf', 16, 13],
                // On the walls.
                ['painting', 9, 0], ['window', 14, 0], ['painting', 20, 0], ['clock', 2, 0],
                ['noticeboard', 10, 12], ['shelf', 18, 12],
                // Odds and ends.
                ['plant', 1, 1], ['plant', 28, 1], ['bookshelf', 1, 18], ['crate', 28, 18],
            ]),
            'spawn' => ['x' => 15, 'y' => 11],
        ];
    }

    /**
     * Four seating corners in an open room — somewhere to hang about rather than to meet. 24×16.
     *
     * The zones are the *ring of floor around* each cluster, not the sofa: a zone here is where
     * you sit, not what you sit on, which is why one is allowed to contain furniture and only
     * has to contain somewhere to stand.
     */
    private static function lounge(): array
    {
        $w = 24;
        $h = 16;
        $tiles = self::room($w, $h, Tiles::WOOD);

        // A carpeted walkway down the middle, so the four corners read as four corners.
        self::rect($tiles, 10, 1, 4, 14, Tiles::CARPET);

        return [
            'label' => 'Lounge',
            'description' => 'Four seating corners round a carpeted walkway, with a TV and an arcade',
            'name' => 'Lounge',
            'width' => $w,
            'height' => $h,
            'tiles' => $tiles,
            'zones' => [
                ['id' => 'table-nw', 'name' => 'North-west corner', 'kind' => 'private', 'x' => 3, 'y' => 2, 'w' => 5, 'h' => 5],
                ['id' => 'table-ne', 'name' => 'North-east corner', 'kind' => 'private', 'x' => 16, 'y' => 2, 'w' => 5, 'h' => 5],
                ['id' => 'table-sw', 'name' => 'South-west corner', 'kind' => 'private', 'x' => 3, 'y' => 9, 'w' => 5, 'h' => 5],
                ['id' => 'table-se', 'name' => 'South-east corner', 'kind' => 'private', 'x' => 16, 'y' => 9, 'w' => 5, 'h' => 5],
            ],
            'objects' => self::objects([
                // NW: somewhere to sit and put a record on.
                ['rug', 4, 3], ['couch', 4, 3], ['speaker', 6, 5], ['stool', 4, 5],
                // NE: the TV corner.
                ['rug', 17, 3], ['tv', 17, 3], ['couch', 17, 5], ['stool', 19, 6],
                // SW: the quiet corner.
                ['rug', 4, 10], ['bookshelf', 4, 10], ['couch', 5, 12], ['plant', 3, 12],
                // SE: the machines.
                ['arcade', 17, 10], ['racer', 19, 10], ['stool', 17, 12], ['stool', 19, 12],
                // Round the edges.
                ['plant', 1, 1], ['plant', 22, 1], ['watercooler', 1, 14], ['fridge', 22, 14],
                ['painting', 6, 0], ['window', 11, 0], ['painting', 17, 0], ['clock', 12, 0],
                ['mat', 11, 14],
            ]),
            'spawn' => ['x' => 11, 'y' => 13],
        ];
    }

    /**
     * Outdoors: grass, a pond, a path through the middle and trees round the edge. 28×20.
     *
     * The one preset with no walls at all. Distance is the only thing separating anybody, and
     * the trees are there to give you something to walk *behind* — a room you can see all of at
     * once is a room where standing somewhere in particular means nothing.
     */
    private static function park(): array
    {
        $w = 28;
        $h = 20;

        // All grass, ringed by trees. The ring is the wall — it just doesn't look like one.
        $tiles = self::fill($w, $h, Tiles::GRASS);
        self::border($tiles, Tiles::TREE);

        // A path in from the south, forking east and west across the middle.
        self::rect($tiles, 13, 11, 2, 8, Tiles::PATH);
        self::rect($tiles, 3, 11, 22, 2, Tiles::PATH);

        // The pond, with a sandy shore. Water is solid — you stand at the edge of it.
        self::rect($tiles, 16, 3, 8, 6, Tiles::SAND);
        self::rect($tiles, 17, 4, 6, 4, Tiles::WATER);

        // Somewhere to get lost in, and something to look at.
        self::rect($tiles, 3, 3, 5, 4, Tiles::TALL_GRASS);
        self::rect($tiles, 4, 15, 4, 3, Tiles::FLOWERS);
        self::rect($tiles, 19, 15, 4, 3, Tiles::TALL_GRASS);

        // A few trees inside the field, so the middle isn't one flat sheet.
        foreach ([[10, 5], [11, 8], [9, 15], [24, 15], [6, 9], [21, 17]] as [$x, $y]) {
            self::stamp($tiles, $x, $y, [Tiles::TREE]);
        }

        return [
            'label' => 'Park',
            'description' => 'Grass, a pond and a path through the trees — no walls anywhere',
            'name' => 'Park',
            'width' => $w,
            'height' => $h,
            'tiles' => $tiles,
            'zones' => [],
            'objects' => self::objects([
                // Benches facing the pond and the path.
                ['bench', 17, 10], ['bench', 20, 10], ['bench', 5, 10], ['bench', 8, 13],
                // A bandstand's worth of kit at the north end of the path.
                ['speaker', 14, 9], ['campfire', 12, 16], ['crate', 11, 17], ['barrel', 15, 16],
                // Notice board where the path comes in.
                ['plant', 3, 18], ['plant', 24, 3], ['plant', 25, 18],
            ]),
            'spawn' => ['x' => 13, 'y' => 17],
        ];
    }

    /**
     * One round clearing with a fire in the middle. No zones at all — everybody in one circle,
     * and distance is the only thing between you. 20×20.
     */
    private static function campfire(): array
    {
        $w = 20;
        $h = 20;

        /*
         * The circle, as a mask: how far in the tree line sits on each row. Written out because
         * a hand-drawn circle at this size looks better than a computed one — the arithmetic
         * version has a flat spot at the top and a stair-step at the shoulders.
         */
        $inset = [7, 5, 4, 3, 2, 1, 1, 0, 0, 0, 0, 0, 0, 1, 1, 2, 3, 4, 5, 7];

        $tiles = [];
        foreach ($inset as $y => $edge) {
            $inner = $w - $edge * 2;
            $tiles[] = str_repeat(Tiles::VOID, $edge)
                .Tiles::TREE
                .str_repeat(Tiles::GRASS, max(0, $inner - 2))
                .Tiles::TREE
                .str_repeat(Tiles::VOID, $edge);
        }

        // The fire pit: a patch of sand in the middle with the fire itself standing on it.
        self::rect($tiles, 8, 8, 4, 4, Tiles::SAND);
        self::rect($tiles, 4, 5, 3, 2, Tiles::TALL_GRASS);
        self::rect($tiles, 13, 13, 3, 2, Tiles::FLOWERS);

        return [
            'label' => 'Campfire',
            'description' => 'A clearing in the trees with a fire in the middle — no private corners',
            'name' => 'Campfire',
            'width' => $w,
            'height' => $h,
            'tiles' => $tiles,
            'zones' => [],
            'objects' => self::objects([
                ['campfire', 9, 9], ['crate', 11, 10], ['barrel', 8, 11],
                ['bench', 7, 13], ['bench', 11, 6], ['speaker', 13, 9], ['plant', 6, 8],
            ]),
            'spawn' => ['x' => 9, 'y' => 14],
        ];
    }

    // --- the gyms ---

    /*
     * Six rooms in the shape of the badges: an arena you'd challenge someone in, crossed with the
     * office everything else here is. Each is the same 26×16 shell — a leader's dais at the top,
     * an office desk in the corner, torches down the sides — dressed in its own element. Built,
     * like every preset, so a row can't quietly be the wrong length; the theme is in the floor and
     * the furniture, laid over one scaffold.
     */

    /**
     * The bones every gym shares: the room, the leader's zone, and the furniture that frames an
     * arena regardless of its element. Each gym takes this and dresses it.
     *
     * @return array{tiles: array<int, string>, objects: array<int, array{0: string, 1: int, 2: int}>, zone: array<string, mixed>}
     */
    private static function gymScaffold(string $floor): array
    {
        return [
            'tiles' => self::room(26, 16, $floor),
            'objects' => [
                // Sconces down the long walls.
                ['torch', 2, 1], ['torch', 23, 1], ['torch', 2, 13], ['torch', 23, 13],
                // The challenger's desk — the office half of the mashup, and the computer you'd
                // sign the challenge in on.
                ['desk', 3, 12], ['chair', 3, 11], ['computer', 6, 12],
            ],
            'zone' => ['id' => 'dais', 'name' => 'Leader’s dais', 'kind' => 'private', 'x' => 9, 'y' => 1, 'w' => 8, 'h' => 3],
        ];
    }

    /**
     * Assemble a gym from the scaffold plus its own tiles and furniture.
     *
     * @param  array<int, array{0: int, 1: int, 2: int, 3: int, 4: string}>  $paint  rects of [x, y, w, h, tile]
     * @param  array<int, array{0: string, 1: int, 2: int}>  $extra  themed furniture
     */
    private static function gym(string $label, string $description, string $floor, array $paint, array $extra): array
    {
        $scaffold = self::gymScaffold($floor);
        $tiles = $scaffold['tiles'];

        foreach ($paint as [$x, $y, $w, $h, $tile]) {
            self::rect($tiles, $x, $y, $w, $h, $tile);
        }

        // Trees and the like are tiles, so any solid furniture would land on nothing to stand on;
        // the paint runs first and the scaffold's own furniture assumes plain floor under it, so
        // gyms keep their painted patches clear of the corners the scaffold uses.
        return [
            'label' => $label,
            'description' => $description,
            'name' => $label,
            'width' => 26,
            'height' => 16,
            'tiles' => $tiles,
            'zones' => [$scaffold['zone']],
            'objects' => self::objects([...$scaffold['objects'], ...$extra]),
            'spawn' => ['x' => 12, 'y' => 14],
        ];
    }

    /** Cinnabar — the fire gym. Red carpet, torches, a fire in the middle, volcanic rock. */
    private static function gymCinnabar(): array
    {
        return self::gym(
            'Cinnabar Gym',
            'The fire gym — red carpet, a fire pit and volcanic rock, with a challenge desk',
            Tiles::CARPET,
            [[12, 6, 2, 2, Tiles::SAND]],
            [
                ['statue', 5, 2], ['statue', 20, 2],
                ['torch', 12, 1],
                ['campfire', 12, 6], ['boulder', 8, 9], ['boulder', 17, 9],
                ['tv', 15, 12], ['painting', 12, 0], ['plant', 24, 14],
            ],
        );
    }

    /** Celadon — the grass gym. An indoor garden: grass underfoot, flowers, trees, benches. */
    private static function gymCeladon(): array
    {
        $tiles = self::room(26, 16, Tiles::GRASS);
        self::rect($tiles, 10, 5, 6, 2, Tiles::FLOWERS);
        self::rect($tiles, 3, 8, 3, 3, Tiles::TALL_GRASS);
        self::rect($tiles, 20, 8, 3, 3, Tiles::TALL_GRASS);
        foreach ([[7, 3], [18, 3], [11, 9], [14, 9]] as [$x, $y]) {
            self::stamp($tiles, $x, $y, [Tiles::TREE]);
        }

        $scaffold = self::gymScaffold(Tiles::GRASS);

        return [
            'label' => 'Celadon Gym',
            'description' => 'The grass gym — an indoor garden of flowers and trees, and a desk',
            'name' => 'Celadon Gym',
            'width' => 26,
            'height' => 16,
            'tiles' => $tiles,
            'zones' => [$scaffold['zone']],
            'objects' => self::objects([
                ...$scaffold['objects'],
                ['bench', 8, 10], ['bench', 15, 10], ['plant', 5, 2], ['plant', 20, 2],
                ['noticeboard', 12, 0], ['tv', 18, 12],
            ]),
            'spawn' => ['x' => 12, 'y' => 14],
        ];
    }

    /** Vermilion — a warehouse gym. Crates, barrels, and the arcade cabinets to challenge on. */
    private static function gymVermilion(): array
    {
        return self::gym(
            'Vermilion Gym',
            'A warehouse arena of crates and arcade cabinets, with a challenge desk',
            Tiles::WOOD,
            [],
            [
                ['crate', 6, 2], ['crate', 7, 2], ['barrel', 8, 2],
                ['arcade', 14, 2], ['racer', 17, 2],
                ['crate', 6, 9], ['barrel', 7, 9], ['cabinet', 20, 9],
                ['tv', 15, 12], ['poster', 12, 0], ['plant', 24, 2],
            ],
        );
    }

    /** Azalea — the bug gym. A forest floor of grass and tall grass, boulders and a campfire. */
    private static function gymAzalea(): array
    {
        $tiles = self::room(26, 16, Tiles::GRASS);
        self::rect($tiles, 12, 4, 2, 9, Tiles::PATH);
        self::rect($tiles, 4, 3, 4, 3, Tiles::TALL_GRASS);
        self::rect($tiles, 18, 3, 4, 3, Tiles::TALL_GRASS);
        foreach ([[6, 9], [19, 9]] as [$x, $y]) {
            self::stamp($tiles, $x, $y, [Tiles::TREE]);
        }

        $scaffold = self::gymScaffold(Tiles::GRASS);

        return [
            'label' => 'Azalea Gym',
            'description' => 'The bug gym — a forest of tall grass and boulders around a campfire',
            'name' => 'Azalea Gym',
            'width' => 26,
            'height' => 16,
            'tiles' => $tiles,
            'zones' => [$scaffold['zone']],
            'objects' => self::objects([
                ...$scaffold['objects'],
                ['boulder', 9, 9], ['boulder', 16, 9], ['bench', 11, 11],
                ['plant', 4, 2], ['plant', 21, 2], ['noticeboard', 12, 0], ['arcade', 18, 12],
            ]),
            'spawn' => ['x' => 12, 'y' => 14],
        ];
    }

    /** Olivine — the steel gym. A lighthouse over the sea: water, a beacon, pillars, cold steel. */
    private static function gymOlivine(): array
    {
        $tiles = self::room(26, 16, Tiles::FLOOR);
        // The sea, along the top — furniture stays off it, so this gym skips the dais.
        self::rect($tiles, 1, 1, 24, 2, Tiles::WATER);
        self::rect($tiles, 1, 3, 24, 1, Tiles::SAND);

        return [
            'label' => 'Olivine Gym',
            'description' => 'The steel gym — a lighthouse beacon over the sea, pillars and cold steel',
            'name' => 'Olivine Gym',
            'width' => 26,
            'height' => 16,
            'tiles' => $tiles,
            'zones' => [],
            'objects' => self::objects([
                ['lamp', 12, 6], ['pillar', 4, 7], ['pillar', 21, 7],
                ['statue', 8, 6], ['statue', 17, 6],
                ['fridge', 6, 11], ['cabinet', 8, 11], ['watercooler', 19, 11],
                ['desk', 3, 13], ['chair', 3, 12], ['computer', 6, 13],
                ['tv', 16, 13], ['window', 12, 0], ['torch', 2, 8], ['torch', 23, 8],
            ]),
            'spawn' => ['x' => 12, 'y' => 14],
        ];
    }

    /** Blackthorn — the dragon gym. A cavern of rock and torchlight around a still pool. */
    private static function gymBlackthorn(): array
    {
        $tiles = self::room(26, 16, Tiles::PATH);
        self::rect($tiles, 10, 6, 6, 4, Tiles::SAND);
        self::rect($tiles, 11, 7, 4, 2, Tiles::WATER);

        $scaffold = self::gymScaffold(Tiles::PATH);

        return [
            'label' => 'Blackthorn Gym',
            'description' => 'The dragon gym — a torch-lit cavern of rock around a still pool',
            'name' => 'Blackthorn Gym',
            'width' => 26,
            'height' => 16,
            'tiles' => $tiles,
            'zones' => [$scaffold['zone']],
            'objects' => self::objects([
                ...$scaffold['objects'],
                ['statue', 5, 2], ['statue', 20, 2], ['torch', 12, 1],
                ['boulder', 6, 10], ['boulder', 19, 10], ['boulder', 8, 3], ['boulder', 17, 3],
                ['arcade', 15, 12], ['painting', 12, 0],
            ]),
            'spawn' => ['x' => 12, 'y' => 14],
        ];
    }

    /** Four walls and nothing in them, for somebody who'd rather draw their own. 24×16. */
    private static function blank(): array
    {
        return [
            'label' => 'Blank',
            'description' => 'An empty room to build yourself in the editor',
            'name' => 'Blank',
            'width' => 24,
            'height' => 16,
            'tiles' => self::room(24, 16, Tiles::FLOOR),
            'zones' => [],
            'objects' => [],
            'spawn' => ['x' => 12, 'y' => 8],
        ];
    }

    // --- building blocks ---

    /**
     * A grid of one character.
     *
     * @return array<int, string>
     */
    private static function fill(int $width, int $height, string $tile): array
    {
        return array_fill(0, $height, str_repeat($tile, $width));
    }

    /**
     * A grid of one character with a wall round the outside — an indoor room.
     *
     * @return array<int, string>
     */
    private static function room(int $width, int $height, string $floor): array
    {
        $tiles = self::fill($width, $height, $floor);
        self::border($tiles, Tiles::WALL);

        return $tiles;
    }

    /**
     * Draw a character round the edge of a grid.
     *
     * @param  array<int, string>  $tiles
     */
    private static function border(array &$tiles, string $tile): void
    {
        $width = mb_strlen($tiles[0]);
        $height = count($tiles);

        foreach ($tiles as $y => $row) {
            $tiles[$y] = $y === 0 || $y === $height - 1
                ? str_repeat($tile, $width)
                : $tile.mb_substr($row, 1, $width - 2).$tile;
        }
    }

    /**
     * Paint a rectangle of one character.
     *
     * @param  array<int, string>  $tiles
     */
    private static function rect(array &$tiles, int $x, int $y, int $w, int $h, string $tile): void
    {
        for ($row = $y; $row < $y + $h; $row++) {
            self::stamp($tiles, $x, $row, [str_repeat($tile, $w)]);
        }
    }

    /**
     * Paste a small picture into the grid at a position, leaving everything else alone.
     *
     * Silently ignores anything that would land off the edge, because a preset that overhangs
     * its own map is a bug in this file rather than something worth an exception at boot.
     *
     * @param  array<int, string>  $tiles
     * @param  array<int, string>  $art
     */
    private static function stamp(array &$tiles, int $x, int $y, array $art): void
    {
        foreach ($art as $i => $line) {
            $row = $tiles[$y + $i] ?? null;

            if ($row === null || $x < 0 || $x + mb_strlen($line) > mb_strlen($row)) {
                continue;
            }

            $tiles[$y + $i] = mb_substr($row, 0, $x).$line.mb_substr($row, $x + mb_strlen($line));
        }
    }

    /**
     * Turn a compact `[kind, x, y]` list into stored decorations.
     *
     * The ids only have to be unique within one map and stable across a seed, so they're
     * positional — nothing ever refers to a preset's furniture by name.
     *
     * @param  array<int, array{0: string, 1: int, 2: int}>  $list
     * @return array<int, array{id: string, kind: string, x: int, y: int}>
     */
    private static function objects(array $list): array
    {
        return array_values(array_map(
            fn (array $item, int $i) => ['id' => "d-$i", 'kind' => $item[0], 'x' => $item[1], 'y' => $item[2]],
            $list,
            array_keys($list),
        ));
    }
}
