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
            // The themed four. Same machinery as the plain rooms above — they're only presets —
            // but built to be somewhere rather than something, which is what a room you'd
            // actually hang about in has that a floor plan doesn't.
            'throne-room' => self::throneRoom(),
            'green-hall' => self::greenHall(),
            'sleep-temple' => self::sleepTemple(),
            'espurr-den' => self::espurrDen(),
            'new-york' => self::newYork(),
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
     * Which heading a preset sits under in the picker.
     *
     * A lookup rather than a field on each preset, because grouping is a fact about the *list* —
     * it exists only so seventeen rooms in a grid read as three short choices instead of one long
     * scroll — and threading it through seventeen builders would put a presentation concern in
     * the middle of the geometry. The order of {@see self::GROUPS} is the order they're shown in.
     */
    public const GROUPS = [
        'Rooms' => ['office', 'lounge', 'park', 'campfire', 'blank'],
        'Themed' => ['throne-room', 'green-hall', 'sleep-temple', 'espurr-den', 'new-york'],
        'Gyms' => ['gym-cinnabar', 'gym-celadon', 'gym-vermilion', 'gym-azalea', 'gym-olivine', 'gym-blackthorn'],
    ];

    /** The heading this preset belongs under. Anything unlisted falls in with the plain rooms. */
    public static function groupOf(string $key): string
    {
        foreach (self::GROUPS as $group => $keys) {
            if (in_array($key, $keys, true)) {
                return $group;
            }
        }

        return array_key_first(self::GROUPS);
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
                // Meeting room A: a desk to sit around, a computer on it, and the whiteboard
                // you actually stand at — which is the channel's Board, not a second one.
                ['desk', 7, 5], ['chair', 7, 4], ['chair', 9, 4], ['computer', 10, 5],
                ['whiteboard', 10, 4],
                // Meeting room B: the room you go to to watch something together.
                ['tv', 19, 4], ['couch', 19, 6], ['chair', 22, 5],
                // The open floor: desks along the middle, a speaker by the wall.
                ['desk', 8, 9], ['chair', 8, 10], ['computer', 9, 8],
                ['desk', 13, 9], ['chair', 13, 10], ['computer', 14, 8],
                ['desk', 18, 9], ['chair', 18, 10],
                ['speaker', 27, 8], ['watercooler', 27, 9],
                // The lounge corner.
                ['rug', 22, 15], ['couch', 22, 15], ['arcade', 25, 16], ['plant', 22, 17],
                // The back office: where the room's written-down things live — the shared notes
                // on the lectern, the doc shelf in the cabinet.
                ['desk', 8, 13], ['chair', 8, 14], ['easel', 12, 13], ['bookshelf', 16, 13],
                ['lectern', 14, 13], ['filecabinet', 17, 13],
                // On the walls.
                ['painting', 9, 0], ['window', 14, 0], ['painting', 20, 0], ['clock', 2, 0],
                ['noticeboard', 10, 12], ['shelf', 18, 12], ['planner', 14, 12],
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

    // --- the themed rooms ---

    /**
     * A great hall: one long aisle, and a throne of swords at the end of it. 26×20.
     *
     * The shape is doing the theming as much as the furniture is. Everything points north —
     * the carpet runner, the pillars, the braziers — so that walking in puts you at the bottom
     * of a room whose whole geometry is about who is sitting at the top of it. The two rooms
     * off it are the counterweight: a sealed council chamber where the actual arguing happens,
     * and a walled garden with a tree in it where it doesn't.
     */
    private static function throneRoom(): array
    {
        $w = 26;
        $h = 20;
        $tiles = self::room($w, $h, Tiles::FLOOR);

        // The aisle, and the raised boards at the end of it. Order matters: the dais is painted
        // over the runner, because the throne stands on the platform, not on the carpet.
        self::rect($tiles, 11, 1, 4, 18, Tiles::CARPET);
        self::rect($tiles, 9, 1, 8, 3, Tiles::WOOD);

        // The small council: sealed, one door, and carpeted so it doesn't read as a cell.
        self::chamber($tiles, 0, 12, 8, 8, Tiles::FLOOR, [[7, 15]]);
        self::rect($tiles, 1, 13, 6, 6, Tiles::CARPET);

        // The godswood: the same box with grass and a couple of trees in it, so the room has
        // one corner that isn't stone.
        self::rect($tiles, 19, 13, 6, 6, Tiles::GRASS);
        self::chamber($tiles, 18, 12, 8, 8, Tiles::GRASS, [[18, 15]]);
        self::stamp($tiles, 20, 14, [Tiles::TREE]);
        self::stamp($tiles, 23, 17, [Tiles::TREE]);

        return [
            'label' => 'Throne Room',
            'description' => 'A great hall of stone and braziers with an iron throne at the head of it',
            'name' => 'Throne Room',
            'width' => $w,
            'height' => $h,
            'tiles' => $tiles,
            'zones' => [
                ['id' => 'council', 'name' => 'Small council', 'kind' => 'private', 'x' => 1, 'y' => 13, 'w' => 6, 'h' => 6],
                ['id' => 'godswood', 'name' => 'Godswood', 'kind' => 'private', 'x' => 19, 'y' => 13, 'w' => 6, 'h' => 6],
            ],
            'objects' => self::objects([
                // The seat, and the two fires that say it's the seat.
                ['throne', 12, 2], ['torch', 10, 2], ['torch', 15, 2],
                ['statue', 9, 1], ['statue', 16, 1],
                // The hall: pillars down both sides and braziers between them.
                ['pillar', 8, 5], ['pillar', 8, 8], ['pillar', 8, 11],
                ['pillar', 17, 5], ['pillar', 17, 8], ['pillar', 17, 11],
                ['torch', 9, 6], ['torch', 16, 6], ['torch', 9, 10], ['torch', 16, 10],
                // Long tables along the walls, for the feast the hall is otherwise too tidy for.
                ['desk', 4, 8], ['bench', 4, 9], ['desk', 20, 8], ['bench', 20, 9],
                ['barrel', 3, 5], ['crate', 21, 5], ['speaker', 6, 3],
                // The council chamber: the map on the wall, the ledgers, somewhere to sit.
                ['whiteboard', 2, 13], ['bookshelf', 1, 13], ['lectern', 5, 13],
                ['desk', 2, 15], ['chair', 2, 14], ['chair', 3, 14], ['bench', 2, 17],
                ['filecabinet', 6, 18], ['plant', 6, 16],
                // The godswood.
                ['bench', 20, 17], ['bench', 22, 14], ['plant', 19, 18], ['torch', 24, 13],
                // Banners.
                ['painting', 5, 0], ['painting', 20, 0], ['poster', 2, 0], ['clock', 12, 0],
                ['mat', 12, 18], ['mat', 13, 18],
            ]),
            'spawn' => ['x' => 12, 'y' => 18],
        ];
    }

    /**
     * A timber hall opening onto a glade with a stream through it. 28×20.
     *
     * Half indoors and half out, joined by one short path — the only preset where the door in
     * the middle is the whole design. Inside is the long table and the hearth; outside is trees,
     * water and flowers, and the two halves are close enough that the hall's noise reaches the
     * grass. Ringed by trees rather than walls on the outside, like the Park.
     */
    private static function greenHall(): array
    {
        $w = 28;
        $h = 20;

        $tiles = self::fill($w, $h, Tiles::GRASS);
        self::border($tiles, Tiles::TREE);

        // The hall: boards inside a box of wall, with a double doorway onto the glade.
        self::rect($tiles, 1, 2, 14, 16, Tiles::WOOD);
        self::chamber($tiles, 1, 2, 14, 16, Tiles::WOOD, [[14, 9], [14, 10]]);

        // A stone hearth in the corner, and a runner down the length of the table.
        self::rect($tiles, 2, 3, 4, 2, Tiles::PATH);
        self::rect($tiles, 6, 4, 4, 12, Tiles::CARPET);

        // The stream, with sand banks and a ford at each end — water is solid, so the shallow
        // ends are the only way across, which is what makes the far side feel like the far side.
        self::rect($tiles, 19, 1, 4, 18, Tiles::SAND);
        self::rect($tiles, 20, 2, 2, 16, Tiles::WATER);

        // The path from the hall door to the water.
        self::rect($tiles, 15, 9, 4, 2, Tiles::PATH);

        self::rect($tiles, 24, 3, 3, 4, Tiles::FLOWERS);
        self::rect($tiles, 24, 13, 3, 4, Tiles::TALL_GRASS);

        foreach ([[17, 4], [17, 15], [25, 9], [23, 11]] as [$x, $y]) {
            self::stamp($tiles, $x, $y, [Tiles::TREE]);
        }

        return [
            'label' => 'Green Hall',
            'description' => 'A timber feast hall opening onto a glade with a stream and flowers',
            'name' => 'Green Hall',
            'width' => $w,
            'height' => $h,
            'tiles' => $tiles,
            'zones' => [
                ['id' => 'hall', 'name' => 'The hall', 'kind' => 'private', 'x' => 2, 'y' => 3, 'w' => 12, 'h' => 14],
                ['id' => 'glade', 'name' => 'The glade', 'kind' => 'private', 'x' => 23, 'y' => 2, 'w' => 4, 'h' => 16],
            ],
            'objects' => self::objects([
                // The long table: two rows of four, benches on both sides of each.
                ['desk', 6, 5], ['bench', 6, 4], ['bench', 6, 6],
                ['desk', 8, 5], ['bench', 8, 4], ['bench', 8, 6],
                ['desk', 6, 9], ['bench', 6, 8], ['bench', 6, 10],
                ['desk', 8, 9], ['bench', 8, 8], ['bench', 8, 10],
                // The hearth end.
                ['campfire', 3, 3], ['barrel', 2, 6], ['crate', 3, 6], ['speaker', 12, 12],
                // The written-down half of a hall: songs, maps, records.
                ['bookshelf', 12, 3], ['cabinet', 12, 4], ['lectern', 12, 6], ['filecabinet', 12, 8],
                // The soft end.
                ['rug', 2, 11], ['couch', 2, 13], ['plant', 2, 16], ['plant', 13, 16],
                // The doors themselves.
                ['door', 14, 9], ['door', 14, 10],
                ['painting', 4, 2], ['window', 9, 2], ['painting', 12, 2],
                // Outside: torches at the door, benches by the water, a stone figure in the trees.
                ['torch', 15, 8], ['torch', 15, 11],
                ['bench', 23, 7], ['bench', 23, 14], ['statue', 24, 10],
                ['plant', 26, 5], ['plant', 26, 15], ['crate', 17, 17], ['barrel', 18, 17],
            ]),
            'spawn' => ['x' => 16, 'y' => 10],
        ];
    }

    /**
     * A dark temple: pews, an aisle, and a stage you'd worship at. 22×22.
     *
     * Built as a venue rather than a room. The pews all face the same way, the aisle runs the
     * length of it, and everything worth pressing E on — the speakers most of all — is up on the
     * boards at the north end, because the point of the room is the thing happening at the front
     * of it. The two water alcoves are where you go when you want to stop being in the crowd.
     */
    private static function sleepTemple(): array
    {
        $w = 22;
        $h = 22;
        $tiles = self::room($w, $h, Tiles::FLOOR);

        // Carpet everywhere inside, a stone aisle cut through it, boards for the stage.
        self::rect($tiles, 2, 2, 18, 18, Tiles::CARPET);
        self::rect($tiles, 10, 4, 2, 16, Tiles::PATH);
        self::rect($tiles, 6, 2, 10, 5, Tiles::WOOD);

        // Two still pools, one either side. Sand ring, water in the middle: you stand at the
        // edge of it, which is the only thing you can do with water here anyway.
        foreach ([2, 16] as $x0) {
            self::rect($tiles, $x0, 9, 4, 4, Tiles::SAND);
            self::rect($tiles, $x0 + 1, 10, 2, 2, Tiles::WATER);
        }

        return [
            'label' => 'Sleep Temple',
            'description' => 'A dark hall of pews facing a candlelit stage, with still water either side',
            'name' => 'Sleep Temple',
            'width' => $w,
            'height' => $h,
            'tiles' => $tiles,
            'zones' => [
                ['id' => 'stage', 'name' => 'The stage', 'kind' => 'private', 'x' => 6, 'y' => 2, 'w' => 10, 'h' => 5],
                ['id' => 'pool-west', 'name' => 'West pool', 'kind' => 'private', 'x' => 2, 'y' => 9, 'w' => 4, 'h' => 4],
                ['id' => 'pool-east', 'name' => 'East pool', 'kind' => 'private', 'x' => 16, 'y' => 9, 'w' => 4, 'h' => 4],
            ],
            'objects' => self::objects([
                // The stage: two masked figures, the stacks, and the book you read from.
                ['statue', 10, 2], ['statue', 11, 2],
                ['speaker', 7, 2], ['speaker', 14, 2], ['lectern', 8, 4],
                ['torch', 6, 2], ['torch', 15, 2], ['torch', 6, 6], ['torch', 15, 6],
                ['campfire', 8, 6], ['campfire', 13, 6],
                // The pews: four rows either side of the aisle, all facing the stage.
                ['bench', 7, 9], ['bench', 12, 9],
                ['bench', 7, 11], ['bench', 12, 11],
                ['bench', 7, 13], ['bench', 12, 13],
                ['bench', 7, 15], ['bench', 12, 15],
                // Candles down the aisle, in the gaps the pews leave.
                ['torch', 9, 10], ['torch', 12, 10], ['torch', 9, 16], ['torch', 12, 16],
                ['pillar', 5, 8], ['pillar', 5, 16], ['pillar', 16, 8], ['pillar', 16, 16],
                // The pools.
                ['torch', 5, 9], ['torch', 16, 9], ['barrel', 2, 12], ['crate', 19, 12],
                ['poster', 10, 0], ['poster', 11, 0], ['painting', 4, 0], ['painting', 17, 0],
                ['noticeboard', 10, 21],
                ['mat', 10, 20], ['mat', 11, 20],
            ]),
            'spawn' => ['x' => 10, 'y' => 19],
        ];
    }

    /**
     * A den full of plush Espurrs, with a garden corner and a small pond. 22×16.
     *
     * The one room here that isn't trying to be impressive. Carpet everywhere, soft furniture,
     * something to play on, and the plushes scattered about the way toys actually end up —
     * a few together in the nest, one abandoned in the middle of the floor, one out in the grass.
     */
    private static function espurrDen(): array
    {
        $w = 22;
        $h = 16;
        $tiles = self::room($w, $h, Tiles::CARPET);

        // The nest: boards in the corner, which is where the pile of plushes lives.
        self::rect($tiles, 2, 2, 8, 5, Tiles::WOOD);

        // A patch of garden that has somehow got indoors, and a pond in the other corner.
        self::rect($tiles, 15, 10, 6, 5, Tiles::GRASS);
        self::rect($tiles, 16, 11, 3, 2, Tiles::FLOWERS);
        self::rect($tiles, 17, 2, 4, 4, Tiles::SAND);
        self::rect($tiles, 18, 3, 2, 2, Tiles::WATER);

        return [
            'label' => 'Espurr Den',
            'description' => 'A carpeted den of plush Espurrs, with a garden corner and a small pond',
            'name' => 'Espurr Den',
            'width' => $w,
            'height' => $h,
            'tiles' => $tiles,
            'zones' => [
                ['id' => 'nest', 'name' => 'The nest', 'kind' => 'private', 'x' => 2, 'y' => 2, 'w' => 8, 'h' => 5],
                ['id' => 'garden', 'name' => 'Garden corner', 'kind' => 'private', 'x' => 15, 'y' => 10, 'w' => 6, 'h' => 5],
            ],
            'objects' => self::objects([
                // The pile. All three outfits, because a collection is the point of a collection.
                ['plush', 3, 3], ['plush_vessel', 5, 3], ['plush_pickachu', 7, 3],
                ['plush', 4, 5], ['plush_vessel', 8, 5],
                // And the strays.
                ['plush_pickachu', 12, 8], ['plush', 2, 13], ['plush_vessel', 16, 12], ['plush', 19, 13],
                // The soft corner, facing the telly.
                ['tv', 3, 10], ['rug', 5, 10], ['couch', 5, 12], ['couch', 3, 8], ['speaker', 1, 10],
                // Somewhere to make something.
                ['desk', 12, 6], ['stool', 12, 7], ['stool', 13, 7], ['easel', 15, 6],
                ['arcade', 19, 8], ['racer', 20, 8],
                // Shelves and lamps.
                ['bookshelf', 10, 1], ['cabinet', 11, 1], ['lamp', 1, 1], ['lamp', 20, 1],
                ['bench', 16, 14], ['plant', 15, 10], ['plant', 20, 14],
                ['shelf', 5, 0], ['painting', 8, 0], ['window', 14, 0], ['clock', 3, 0],
                ['mat', 10, 14],
            ]),
            'spawn' => ['x' => 10, 'y' => 13],
        ];
    }

    /**
     * A city block: two sidewalks, the street between them, and rooms off it. 30×22.
     *
     * The only preset built round a *thoroughfare*. Everything faces the street, the street is
     * the only way from one end to the other, and the three interiors — a diner, a bodega, an
     * apartment lobby — open onto it. Which makes it the room where you keep bumping into
     * people, because a corridor with doors off it is exactly what a busy channel wants and
     * exactly what an open floor plan never gives you.
     *
     * The little park at the east end is the counterweight: somewhere with grass and a bench
     * that you can see the street from without standing in it.
     */
    private static function newYork(): array
    {
        $w = 30;
        $h = 22;

        // Concrete everywhere, with the road as a band of worn path down the middle. Bordered
        // with wall rather than trees: a city block ends at a building, not at a hedge.
        $tiles = self::room($w, $h, Tiles::FLOOR);
        self::rect($tiles, 1, 9, 28, 4, Tiles::PATH);

        // The crossing: two stripes of boards across the road, which is what a zebra crossing
        // is at this resolution — a marked place to step off the kerb.
        self::rect($tiles, 11, 9, 1, 4, Tiles::WOOD);
        self::rect($tiles, 13, 9, 1, 4, Tiles::WOOD);

        // North side: the diner and the bodega, each a sealed room with a door onto the street.
        self::chamber($tiles, 2, 1, 10, 8, Tiles::FLOOR, [[6, 8]]);
        self::rect($tiles, 3, 2, 8, 6, Tiles::WOOD);
        self::chamber($tiles, 15, 1, 9, 8, Tiles::FLOOR, [[19, 8]]);
        self::rect($tiles, 16, 2, 7, 6, Tiles::CARPET);

        // South side: the apartment lobby, and the pocket park beside it.
        self::chamber($tiles, 3, 13, 9, 8, Tiles::FLOOR, [[7, 13]]);
        self::rect($tiles, 4, 14, 7, 6, Tiles::CARPET);

        self::rect($tiles, 16, 14, 12, 7, Tiles::GRASS);
        self::rect($tiles, 20, 16, 4, 3, Tiles::FLOWERS);
        foreach ([[17, 15], [26, 15], [17, 20], [26, 20]] as [$x, $y]) {
            self::stamp($tiles, $x, $y, [Tiles::TREE]);
        }

        return [
            'label' => 'New York',
            'description' => 'A city block: a street with a diner, a bodega, a lobby and a pocket park',
            'name' => 'New York',
            'width' => $w,
            'height' => $h,
            'tiles' => $tiles,
            'zones' => [
                ['id' => 'diner', 'name' => 'The diner', 'kind' => 'private', 'x' => 3, 'y' => 2, 'w' => 8, 'h' => 6],
                ['id' => 'bodega', 'name' => 'The bodega', 'kind' => 'private', 'x' => 16, 'y' => 2, 'w' => 7, 'h' => 6],
                ['id' => 'lobby', 'name' => 'The lobby', 'kind' => 'private', 'x' => 4, 'y' => 14, 'w' => 7, 'h' => 6],
                ['id' => 'park', 'name' => 'The park', 'kind' => 'private', 'x' => 16, 'y' => 14, 'w' => 12, 'h' => 7],
            ],
            'objects' => self::objects([
                // The diner: a counter of stools, booths against the window, a jukebox.
                ['desk', 4, 3], ['desk', 6, 3], ['desk', 8, 3],
                ['stool', 4, 4], ['stool', 6, 4], ['stool', 8, 4], ['stool', 10, 4],
                ['bench', 4, 6], ['bench', 8, 6], ['fridge', 10, 2], ['speaker', 3, 7],
                ['painting', 5, 1], ['window', 8, 1], ['clock', 3, 1],
                ['door', 6, 8],
                // The bodega: shelves, a counter, and the telly nobody is watching.
                ['bookshelf', 16, 2], ['cabinet', 17, 2], ['bookshelf', 18, 2],
                ['fridge', 22, 2], ['fridge', 22, 3],
                ['desk', 16, 6], ['computer', 18, 6], ['crate', 21, 6], ['barrel', 22, 7],
                ['tv', 20, 2], ['poster', 17, 1], ['window', 21, 1],
                ['door', 19, 8],
                // The street itself: what makes it a street rather than a corridor.
                ['lamp', 13, 8], ['lamp', 13, 13], ['lamp', 26, 8],
                ['noticeboard', 24, 0], ['plant', 2, 10], ['plant', 27, 11],
                ['crate', 25, 10], ['barrel', 26, 10], ['watercooler', 14, 8],
                // The lobby: post, a sofa nobody sits on, and the board by the door.
                ['couch', 5, 15], ['rug', 5, 17], ['filecabinet', 4, 19], ['lectern', 9, 15],
                ['plant', 10, 19], ['lamp', 4, 14], ['whiteboard', 8, 19],
                ['door', 7, 13],
                // The park.
                ['bench', 18, 17], ['bench', 24, 17], ['bench', 21, 20],
                ['statue', 22, 14], ['plant', 19, 19], ['campfire', 25, 19],
                ['mat', 12, 11], ['mat', 12, 10],
            ]),
            'spawn' => ['x' => 12, 'y' => 11],
        ];
    }

    // --- the gyms ---

    /*
     * Six rooms in the shape of the badges: an arena you'd challenge someone in, crossed with the
     * office everything else here is.
     *
     * They started out as one 26×16 box with a dais painted at the top, and it showed — a gym whose
     * "rooms" are rectangles of tint is a floor plan with a theme, and the thing that makes
     * proximity worth having is *walls*. So the shell is a building now: you come in at the south
     * into a lobby, walk up through a doorway into the arena, and there are three sealed rooms off
     * it — a leader's chamber at the far end and a wing on each side, each a private zone you can
     * genuinely close a conversation inside. 36×24, which is about four times the floor of the old
     * shell and roughly as big as a room can be before crossing it is a chore.
     *
     * One corridor (x 16–19) runs the length of it, front door to chamber door, and every gym keeps
     * it clear: a themed pond or tree line that sealed the arena off from its own entrance would be
     * a room nobody can get into. See {@see gym}.
     */

    /** The gym shell's size. All six are this, dressed differently. */
    private const GYM_W = 36;

    private const GYM_H = 24;

    /**
     * The bones every gym shares: the building, its three sealed rooms, and the furniture that
     * frames an arena regardless of its element. Each gym takes this and dresses it.
     *
     * @return array{
     *     tiles: array<int, string>,
     *     objects: array<int, array{0: string, 1: int, 2: int}>,
     *     zones: array<int, array<string, mixed>>
     * }
     */
    private static function gymScaffold(string $floor): array
    {
        $tiles = self::room(self::GYM_W, self::GYM_H, $floor);

        // The leader's chamber, at the far end, entered through a two-tile gap in its south wall.
        self::chamber($tiles, 12, 0, 12, 7, $floor, [[17, 6], [18, 6]]);

        // A wing either side of the arena, each entered from it.
        self::chamber($tiles, 0, 7, 10, 8, $floor, [[9, 10], [9, 11]]);
        self::chamber($tiles, 26, 7, 10, 8, $floor, [[26, 10], [26, 11]]);

        // The lobby: a wall across the room with the front door in the middle of it. Everything
        // south of it is the hall you arrive in — deliberately *not* a zone, because the point of
        // an entrance is that the room can hear you come in.
        self::rect($tiles, 1, 18, 34, 1, Tiles::WALL);
        self::rect($tiles, 17, 18, 2, 1, $floor);

        return [
            'tiles' => $tiles,
            'objects' => [
                // The leader's chamber: statues either side of the way in, torchlight behind them.
                ['statue', 15, 2], ['statue', 20, 2], ['torch', 14, 1], ['torch', 21, 1],
                ['painting', 17, 0], ['window', 18, 0],

                // The west wing is the office half of the mashup: the desk you'd sign a challenge
                // in at, and the board of who has beaten whom.
                ['desk', 2, 9], ['chair', 2, 10], ['computer', 5, 9],
                ['bookshelf', 1, 12], ['plant', 8, 13], ['noticeboard', 4, 7],

                // The east wing is where you wait your turn.
                ['tv', 28, 9], ['couch', 28, 12], ['arcade', 32, 8], ['racer', 32, 12],
                ['stool', 31, 12], ['plant', 34, 13], ['shelf', 30, 7],

                // Sconces at the arena's four corners.
                ['torch', 10, 7], ['torch', 25, 7], ['torch', 10, 17], ['torch', 25, 17],

                // The lobby: somewhere to sit, something to put on, and the mat you walk in over.
                ['bench', 12, 21], ['bench', 22, 21], ['watercooler', 2, 21], ['easel', 5, 20],
                ['speaker', 32, 20], ['mat', 17, 22], ['mat', 18, 22], ['plant', 33, 22],
                ['noticeboard', 10, 18], ['poster', 25, 18], ['clock', 17, 23],
            ],
            'zones' => [
                ['id' => 'dais', 'name' => 'Leader’s chamber', 'kind' => 'private', 'x' => 13, 'y' => 1, 'w' => 10, 'h' => 5],
                ['id' => 'west-wing', 'name' => 'Challengers’ room', 'kind' => 'private', 'x' => 1, 'y' => 8, 'w' => 8, 'h' => 6],
                ['id' => 'east-wing', 'name' => 'Waiting room', 'kind' => 'private', 'x' => 27, 'y' => 8, 'w' => 8, 'h' => 6],
            ],
        ];
    }

    /**
     * Assemble a gym from the scaffold plus its own ground and furniture.
     *
     * The paint runs *first* and the scaffold's furniture assumes plain floor under it, so a gym's
     * rects keep out of the three rooms and the lobby and confine themselves to the arena and the
     * two alcoves either side of the chamber — which is where a theme wants to be anyway.
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

        return [
            'label' => $label,
            'description' => $description,
            'name' => $label,
            'width' => self::GYM_W,
            'height' => self::GYM_H,
            'tiles' => $tiles,
            'zones' => $scaffold['zones'],
            'objects' => self::objects([...$scaffold['objects'], ...$extra]),
            'spawn' => ['x' => 17, 'y' => 21],
        ];
    }

    /** Cinnabar — the fire gym. Red carpet, a sand pit with a fire in it, volcanic rock. */
    private static function gymCinnabar(): array
    {
        return self::gym(
            'Cinnabar Gym',
            'The fire gym — a carpeted hall round a blazing sand pit, with two wings and a challenge desk',
            Tiles::CARPET,
            [
                [13, 9, 10, 7, Tiles::SAND],
                [2, 2, 6, 4, Tiles::SAND],
                [28, 2, 6, 4, Tiles::SAND],
            ],
            [
                ['campfire', 17, 12],
                ['boulder', 14, 10], ['boulder', 21, 10], ['boulder', 14, 14], ['boulder', 21, 14],
                ['torch', 13, 9], ['torch', 22, 9], ['torch', 13, 15], ['torch', 22, 15],
                ['statue', 3, 3], ['statue', 32, 3], ['barrel', 6, 5], ['crate', 29, 5],
                ['painting', 7, 0], ['window', 28, 0],
            ],
        );
    }

    /** Celadon — the grass gym. An indoor garden: grass underfoot, flowers, trees, benches. */
    private static function gymCeladon(): array
    {
        return self::gym(
            'Celadon Gym',
            'The grass gym — an indoor garden of flowers and trees, with two wings and a challenge desk',
            Tiles::GRASS,
            [
                [13, 10, 10, 5, Tiles::FLOWERS],
                [2, 2, 6, 4, Tiles::TALL_GRASS],
                [28, 2, 6, 4, Tiles::TALL_GRASS],
                // Four trees in the arena, so the middle isn't one flat lawn. A 1×1 rect says
                // "a tree here" without needing a second helper.
                [12, 8, 1, 1, Tiles::TREE], [23, 8, 1, 1, Tiles::TREE],
                [12, 16, 1, 1, Tiles::TREE], [23, 16, 1, 1, Tiles::TREE],
            ],
            [
                ['bench', 14, 16], ['bench', 20, 16], ['bench', 14, 9], ['bench', 20, 9],
                ['plant', 11, 12], ['plant', 24, 12], ['lamp', 11, 9], ['lamp', 24, 9],
                ['statue', 3, 3], ['statue', 32, 3],
                ['window', 7, 0], ['window', 28, 0],
            ],
        );
    }

    /** Vermilion — a warehouse gym. Crates, barrels, and the cabinets to challenge on. */
    private static function gymVermilion(): array
    {
        return self::gym(
            'Vermilion Gym',
            'A warehouse arena of crates and cabinets, with two wings and a challenge desk',
            Tiles::WOOD,
            [
                // Lanes worn into the boards, and a swept floor either side of the chamber.
                [16, 7, 4, 11, Tiles::PATH],
                [2, 2, 6, 4, Tiles::FLOOR],
                [28, 2, 6, 4, Tiles::FLOOR],
            ],
            [
                ['crate', 12, 9], ['crate', 13, 9], ['barrel', 14, 9],
                ['crate', 21, 9], ['crate', 22, 9], ['barrel', 23, 9],
                ['crate', 12, 16], ['barrel', 13, 16], ['crate', 22, 16], ['barrel', 21, 16],
                ['cabinet', 11, 12], ['cabinet', 24, 12], ['fridge', 11, 13], ['fridge', 24, 13],
                ['crate', 3, 3], ['crate', 4, 3], ['barrel', 6, 4], ['crate', 30, 3], ['barrel', 32, 4],
                ['poster', 7, 0], ['poster', 28, 0],
            ],
        );
    }

    /** Azalea — the bug gym. A forest floor of grass and tall grass round a campfire. */
    private static function gymAzalea(): array
    {
        return self::gym(
            'Azalea Gym',
            'The bug gym — a forest of tall grass and boulders round a campfire, with two wings',
            Tiles::GRASS,
            [
                // A path in from the door, and thickets to lose people in.
                [16, 7, 4, 11, Tiles::PATH],
                [11, 8, 5, 4, Tiles::TALL_GRASS],
                [20, 8, 5, 4, Tiles::TALL_GRASS],
                [11, 14, 5, 4, Tiles::TALL_GRASS],
                [20, 14, 5, 4, Tiles::TALL_GRASS],
                [2, 2, 6, 4, Tiles::TALL_GRASS],
                [28, 2, 6, 4, Tiles::TALL_GRASS],
                [12, 12, 1, 1, Tiles::TREE], [23, 12, 1, 1, Tiles::TREE],
            ],
            [
                ['campfire', 17, 12],
                ['boulder', 14, 13], ['boulder', 21, 13], ['boulder', 11, 16], ['boulder', 24, 16],
                ['bench', 14, 16], ['bench', 20, 16],
                ['plant', 3, 3], ['plant', 32, 3], ['barrel', 6, 5], ['crate', 29, 5],
                ['window', 7, 0], ['window', 28, 0],
            ],
        );
    }

    /** Olivine — the steel gym. A lighthouse over the sea: pools, a beacon, pillars, cold steel. */
    private static function gymOlivine(): array
    {
        return self::gym(
            'Olivine Gym',
            'The steel gym — a beacon between four pools, pillars and cold steel, with two wings',
            Tiles::FLOOR,
            [
                // Pools either side of the corridor, sanded at the edges. Kept off x 16–19, so the
                // way from the front door to the chamber is never under water.
                [11, 9, 4, 4, Tiles::SAND], [12, 10, 2, 2, Tiles::WATER],
                [21, 9, 4, 4, Tiles::SAND], [22, 10, 2, 2, Tiles::WATER],
                [11, 14, 4, 4, Tiles::SAND], [12, 15, 2, 2, Tiles::WATER],
                [21, 14, 4, 4, Tiles::SAND], [22, 15, 2, 2, Tiles::WATER],
                [2, 2, 6, 4, Tiles::WATER],
                [28, 2, 6, 4, Tiles::WATER],
            ],
            [
                // The beacon itself, on the corridor where everybody walks past it.
                ['lamp', 17, 9], ['lamp', 18, 16],
                ['pillar', 11, 8], ['pillar', 24, 8], ['pillar', 11, 17], ['pillar', 24, 17],
                ['statue', 15, 12], ['statue', 20, 12],
                ['cabinet', 16, 17], ['fridge', 19, 17],
                ['painting', 7, 0], ['painting', 28, 0],
            ],
        );
    }

    /** Blackthorn — the dragon gym. A cavern of rock and torchlight round a still pool. */
    private static function gymBlackthorn(): array
    {
        return self::gym(
            'Blackthorn Gym',
            'The dragon gym — a torch-lit cavern of rock round two still pools, with two wings',
            Tiles::PATH,
            [
                [12, 9, 4, 4, Tiles::SAND], [13, 10, 2, 2, Tiles::WATER],
                [20, 9, 4, 4, Tiles::SAND], [21, 10, 2, 2, Tiles::WATER],
                [13, 15, 10, 3, Tiles::SAND],
                [2, 2, 6, 4, Tiles::SAND],
                [28, 2, 6, 4, Tiles::SAND],
            ],
            [
                ['boulder', 11, 8], ['boulder', 24, 8], ['boulder', 11, 16], ['boulder', 24, 16],
                ['boulder', 14, 13], ['boulder', 21, 13],
                ['torch', 12, 12], ['torch', 23, 12], ['torch', 16, 16], ['torch', 19, 16],
                ['statue', 3, 3], ['statue', 32, 3], ['barrel', 6, 5], ['crate', 29, 5],
                ['painting', 7, 0], ['painting', 28, 0],
            ],
        );
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
     * A room within a room: a rectangle of wall with doorways punched out of it.
     *
     * The *outline* only — whatever floor is already inside stays, because a chamber's inside is
     * usually the same ground as the hall it stands in and the gyms that want otherwise paint it
     * afterwards. Doors are given as absolute `[x, y]` tiles on the outline rather than as an edge
     * and an offset: two coordinates are easier to check against a map than a side and a length,
     * and a doorway in the wrong place is the one mistake here that makes a room unenterable.
     *
     * @param  array<int, string>  $tiles
     * @param  array<int, array{0: int, 1: int}>  $doors
     */
    private static function chamber(array &$tiles, int $x, int $y, int $w, int $h, string $floor, array $doors): void
    {
        self::rect($tiles, $x, $y, $w, 1, Tiles::WALL);
        self::rect($tiles, $x, $y + $h - 1, $w, 1, Tiles::WALL);
        self::rect($tiles, $x, $y, 1, $h, Tiles::WALL);
        self::rect($tiles, $x + $w - 1, $y, 1, $h, Tiles::WALL);

        foreach ($doors as [$dx, $dy]) {
            self::rect($tiles, $dx, $dy, 1, 1, $floor);
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
     * A fourth element turns the piece ({@see Decorations::FACINGS}); leaving it off is the
     * front view, which is what every preset written before pieces could be turned assumed.
     *
     * @param  array<int, array{0: string, 1: int, 2: int, 3?: string}>  $list
     * @return array<int, array{id: string, kind: string, x: int, y: int, facing?: string}>
     */
    private static function objects(array $list): array
    {
        return array_values(array_map(
            fn (array $item, int $i) => [
                'id' => "d-$i",
                'kind' => $item[0],
                'x' => $item[1],
                'y' => $item[2],
                ...(isset($item[3]) ? ['facing' => $item[3]] : []),
            ],
            $list,
            array_keys($list),
        ));
    }
}
