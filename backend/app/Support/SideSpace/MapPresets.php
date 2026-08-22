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
            // The default a meeting is given when nobody picked a room — see meetingRoom().
            'meeting-room' => self::meetingRoom(),
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
            'gather-town' => self::gatherTown(),
            'nyc-street' => self::nycStreet(),
            'nyc-skyline' => self::nycSkyline(),
            'nyc-island' => self::nycIsland(),
            // The first preset built to be walked *into* rather than opened — see its docblock.
            'movie-theatre' => self::movieTheatre(),
            'met-museum' => self::metMuseum(),
            'outdoor-cinema' => self::outdoorCinema(),
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
        // The meeting room leads: it's the default a meeting gets, so it's the one somebody
        // opening this picker is most likely to be comparing the others against.
        'Rooms' => ['meeting-room', 'office', 'lounge', 'park', 'campfire', 'blank'],
        'Themed' => ['throne-room', 'green-hall', 'sleep-temple', 'espurr-den', 'new-york', 'gather-town', 'nyc-street', 'nyc-skyline', 'nyc-island', 'movie-theatre', 'met-museum', 'outdoor-cinema'],
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
    /**
     * One table, chairs round it, a board and a screen — the room a meeting is given when
     * nobody chose one.
     *
     * Deliberately the smallest room here. A meeting's Side Space has a job the themed rooms
     * don't: everybody who follows the link has to end up *within earshot of each other* within a
     * second of arriving, without being told how to walk. So it is one space, the table is the
     * middle of it, and the spawn is two tiles from the seats — where a bigger room would scatter
     * eight arrivals across four corners and leave them silently alone in the same building.
     *
     * The whiteboard and the wall planner are the Side Desk's own apps standing in the room
     * (see Decorations), so the two things a meeting reaches for — draw this, when is that — are
     * furniture rather than a menu.
     */
    private static function meetingRoom(): array
    {
        $w = 16;
        $h = 12;
        $tiles = self::room($w, $h, Tiles::WOOD);

        // Carpet under the table, so the middle reads as the middle rather than as more floor.
        self::rect($tiles, 4, 3, 8, 6, Tiles::CARPET);

        return [
            'label' => 'Meeting room',
            'description' => 'One table with chairs round it, a whiteboard and a screen — everyone in earshot from the moment they arrive',
            'name' => 'Meeting room',
            'width' => $w,
            'height' => $h,
            'tiles' => $tiles,
            /*
             * A stage zone over the table.
             *
             * Whoever is standing at it is heard map-wide (see the Side Space docs), which is what
             * a room built for a meeting wants by default: somebody presenting shouldn't have to
             * ask everyone to gather round, and the proximity audio everywhere else still applies
             * to the side conversations at the edges.
             */
            'zones' => [
                ['id' => 'table', 'name' => 'The table', 'kind' => 'stage', 'x' => 4, 'y' => 3, 'w' => 8, 'h' => 6],
            ],
            'objects' => self::objects([
                // The table: four desks in a block, chairs down both long sides.
                ['desk', 6, 5], ['desk', 8, 5],
                ['chair', 6, 4], ['chair', 7, 4], ['chair', 8, 4], ['chair', 9, 4],
                ['chair', 6, 7], ['chair', 7, 7], ['chair', 8, 7], ['chair', 9, 7],
                ['chair', 4, 5], ['chair', 11, 5],
                // What a meeting reaches for, as furniture: draw this, watch that, when is it.
                ['whiteboard', 6, 1], ['tv', 9, 1], ['planner', 13, 0],
                // Somewhere to put the coffee, and something to look at.
                ['watercooler', 1, 1], ['plant', 14, 1], ['plant', 1, 10], ['bookshelf', 14, 8],
                ['window', 3, 0], ['clock', 12, 0], ['mat', 8, 10],
            ]),
            // Two tiles from the table, facing it: you arrive already in the room rather than
            // in a doorway wondering which way to walk.
            'spawn' => ['x' => 8, 'y' => 9],
        ];
    }

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
            'label' => 'New York Block',
            'description' => 'A city block: a street with a diner, a bodega, a lobby and a pocket park',
            'name' => 'New York Block',
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

    /**
     * Gather Town: a New York island, drawn as artwork rather than assembled from tiles.
     *
     * The first map with a {@see Backdrops} image, and the reason that mechanism exists. The
     * Financial District's skyline, the neon over Times Square, the brownstones of SoHo and the
     * lake in Central Park are a piece of pixel art; there is no arrangement of sixteen-pixel
     * rectangles that gets there, and pretending otherwise would have produced a recognisably
     * New-York-ish island that looked nothing like the picture.
     *
     * ## The grid below is collision, not scenery
     *
     * Every character in it is invisible. `.` is somewhere you can walk — streets, plazas, the
     * piers, the park and its paths — `#` is a building, and `~` is the harbour and the night sky
     * above it. The picture is what you see; this is what you bump into.
     *
     * ## It is machine-derived, and that is the point
     *
     * Hand-authoring 2,240 tiles against a painting is hours of counting that goes wrong in a
     * dozen places nobody notices until somebody walks into a lamppost. So the grid is generated
     * by sampling the artwork a tile at a time and classifying each by what it is mostly made of
     * — blue is water, grey is asphalt, green is parkland, warm and light is a footpath, and
     * anything else is a building. The generator lives in the scratchpad with the slicer.
     *
     * Two things it could not get from colour alone, both fixed and both worth naming:
     *
     *   - **The bridges.** Brick, and the same hue as the brownstones two streets away. The
     *     island is four landmasses joined by four crossings, so getting these wrong doesn't
     *     make a slightly wrong map — it makes four maps nobody can walk between. The generator
     *     now runs a reachability flood on its own output and reports anything stranded; this
     *     grid has one walkable region holding 96% of its walkable tiles, and no stranded region
     *     bigger than a rooftop.
     *   - **The causeway to Liberty Island**, which was closed at a single tile of darker timber.
     *
     * ## No zones
     *
     * Deliberately. A zone seals sound, and an open city is the one shape where you want the
     * opposite: distance alone deciding who hears whom as people drift between the square, the
     * park and the waterfront. Somebody who wants a sealed room can drag one; nothing here should
     * decide in advance that Times Square is a private meeting.
     */
    private static function gatherTown(): array
    {
        $tiles = [
                '................................................................',
                '................................................................',
                '..~~~~~~~~===~~~#~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~..',
                '..~~~~~~~~=..~.~~~#~..~#~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~..',
                '..~~~~~~~~=..~~~~~.~#~..~~~~~~~~~~~~~~~~~~~~~~~~#~~~~~~~~~~~~~..',
                '..~~~~~~~..~.~~~~~.~.~~~~~~#..~====~~~~~~..~~.~~#~~.#~~~~~~~~~..',
                '..~~~~~~~.~~..#.#..#.~~~~~~~#~~==....~~~~.#~~~~~.~~~~~###~~~~~..',
                '..~~~~~.~.#~....#.....~~~~~#.#~....##~~~~~.~~~~~.~~~~.####~~~~..',
                '..~~~~~..~.~.#..~~...#~#...####...###........#~...#.~.#.#..~~~..',
                '..~~~~.......~.......~~~...#..#..####....#~...~...~#~......~~~..',
                '..~#.......................#~......#....~~#.#.~...~#.......~~~..',
                '..~~~~#..~..............................#~~..~.~.#~~#......~~~..',
                '..~~~~...~...#........~..............#..#~~.......##..##~..~~~..',
                '..~~~~...#.....#..#......................#~............~#..~~~..',
                '..~~.......................................................~~~..',
                '..~~~~.........#.#.........................................~~~..',
                '..~~~~~~~~~~~~~~.~~~~~~~........#....#..~~~~~~~~.#~~~~~~~~~~~~..',
                '..~~~~~~~~~~~~~~.~~~~~~~................~~~~~~~~.#~~~~~~~~~~~~..',
                '..~~~~~~~......~.~..............#........~.#.#...#........~~~~..',
                '..~~~~~~..#.......#~..##.............#..................#..~~~..',
                '..~~~~~~...##.....###.#....................................~~~..',
                '..~~~~==...#............................#....~~~..~~~......~~~..',
                '..~~~#...........................#...#.......~~~~~~~~#...#.~~~..',
                '..~~~~==......~.............#..#..............~~~~~~~#.....~~~..',
                '..~~~....##..##............##.................#~~~~~~......~~~..',
                '..~~..==.##.#..#...#..##...~##.....~~...#~~~...............~~~..',
                '..~~~~~~.##.#....###................~.....~................~~~..',
                '..~~~~~~...~#.~.#...###~.~~~~~~#.~~~~~~~######.....#.##....~~~..',
                '..~~~~~~........#....##..~~~~~~#.~~~~~~~...................~~~..',
                '..~~~~~~~~#~~..#~===~~~~~~~~~~~##~~~~~~~~~~~~~~~~~~~~~~~~~~~~~..',
                '..~~~~~~~~.~~..~~===~~~~~~~~~......~~~~~~~~~~~~~~~~~~~~~~~~~~~..',
                '..~~~~~~~~~~~##~~===~~~~~~~~~......~~~~~~~~~~~~~~~~~~~~~~~~~~~..',
                '..~~~~~~~~~~~~~~~===~~~~~~~~~......~~~~~~~~~~~~~~~~~~~~~~~~~~~..',
                '................................................................',
                '................................................................',
        ];

        return [
            'label' => 'New York',
            'description' => 'A New York island: the Financial District, Times Square, SoHo and Central Park',
            'name' => 'New York',
            'width' => 64,
            'height' => 35,
            'tiles' => $tiles,
            // One placement covering the whole grid — this preset *is* the city. A map that
            // extends it keeps this rectangle and grows around it.
            'backdrops' => [['key' => 'gather-town', 'x' => 0, 'y' => 0, 'w' => 64, 'h' => 35]],
            'zones' => [],
            // Unfurnished. The artwork already has benches, food carts, taxis and trees painted
            // into it, so anything placed here would be a second bench beside a painted one.
            'objects' => [],
            // The middle of Gather Town Square, which is where somebody arriving should arrive.
            'spawn' => ['x' => 28, 'y' => 14],
        ];
    }

    /**
     * Four city blocks around a fountain square. 36×24.
     *
     * The second artwork-backed map, and the one that showed what the generator still cannot do.
     * Its collision grid is derived from the picture the same way New York's is — sample each
     * tile, decide what it is mostly made of — with one extra pass that map did not need:
     * **ground has to be joined to the road** before it counts as walkable. Seen from directly
     * above, a flat roof is an unsaturated rectangle indistinguishable from a pavement. Sampled
     * off this artwork one roof reads 161 and the plaza reads 153; another roof reads 96 and the
     * asphalt beside it reads 95. No threshold separates them, so position does the work instead.
     *
     * It is still imperfect: the low olive rooftops sit close enough to their pavement, at this
     * tile size, that a few of them join the street through a shopfront thinner than one tile and
     * come out walkable. The wall brush fixes those in seconds and this note is here so the next
     * person knows it is a known edge rather than a mystery.
     *
     * The artwork is cropped to 1152×768 rather than used whole. 36 tiles across 1408 pixels
     * would be 39 wide and 32 tall — a fifth of horizontal squash on pixel art, which is worse
     * than losing a strip of a city that repeats anyway.
     */
    private static function nycStreet(): array
    {
        $tiles = [
                '....................................',
                '....................................',
                '...#######....##################....',
                '....#####..#.###########..######....',
                '....#####.##..########.#..#..#......',
                '...........#.......#.....#..####....',
                '..########...#.##...........#####...',
                '....######...#...........##....##...',
                '....#####..#..........#...######....',
                '....#####......###..####..######....',
                '....######.....#.....##..########...',
                '....######.....#.....##...######....',
                '...######.........##......######....',
                '...#######..#.............######....',
                '....######..#.#......##...######.#..',
                '...######......###..###..#.#####.#..',
                '...#######.#...#.....##....#####.#..',
                '....#...#..#....#..#..##............',
                '.....##......#......................',
                '...#######...#.################.#...',
                '...#.........####################...',
                '....#......#..#..#..#.......#..#....',
                '....................................',
                '....................................',
        ];

        return [
            'label' => 'NYC street',
            'description' => 'Four blocks around a fountain square: the bakery, the bookshop, the deli and the cinema',
            'name' => 'NYC street',
            'width' => 36,
            'height' => 24,
            'tiles' => $tiles,
            'backdrops' => [['key' => 'nyc-street', 'x' => 0, 'y' => 0, 'w' => 36, 'h' => 24]],
            'zones' => [],
            // Unfurnished, like the island: the artwork already has benches, carts and a fountain
            // painted into it, and anything placed here would be a second bench beside a drawn one.
            'objects' => [],
            'portals' => [],
            'spawn' => ['x' => 12, 'y' => 12],
        ];
    }

    /**
     * Midtown in the rain at sunset. 48×32.
     *
     * Walkable across the whole city band, with the sunset sky above it and the river below it
     * solid. That is a deliberate trade, and the reasoning is in the generator: three ways of
     * deriving buildings from this artwork were tried and all three failed, because a rainy night
     * render has the streets, the roofs and the water inside about thirty levels of brightness and
     * the rain streaks defeat variance as thoroughly as the wet reflections defeat hue. What the
     * picture *does* say without ambiguity is where it stops being a city — the sky and the water
     * are large, smooth and touch the frame, so a flood from the edges finds them exactly.
     *
     * So you can walk over a building here. At this density, where a roof and a street are the
     * same surface to the eye, that reads as a city you move around freely; the alternative on
     * offer was a street grid two thirds of which was invisible walls, which is worse than an open
     * one. The wall brush makes real blocks in a minute for anybody who wants them.
     */
    private static function nycSkyline(): array
    {
        $tiles = [
                '#################...............################',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '................................................',
                '..###...#....#################..................',
                '#########....########.########..##..............',
                '#########....########.#####################.....',
                '##########...###############################....',
                '##########...###################################',
                '##########..####################################',
        ];

        return [
            'label' => 'NYC skyline, rain',
            'description' => 'Midtown in the rain at sunset — the park, Times Square and the river below',
            'name' => 'NYC skyline',
            'width' => 48,
            'height' => 32,
            'tiles' => $tiles,
            'backdrops' => [['key' => 'nyc-skyline', 'x' => 0, 'y' => 0, 'w' => 48, 'h' => 32]],
            'zones' => [],
            'objects' => [],
            'portals' => [],
            'spawn' => ['x' => 16, 'y' => 16],
        ];
    }

    /**
     * The whole island after dark. 48×32.
     *
     * The one rainy-night map whose streets *are* derived, and it took a different technique to
     * get there. Colour and variance both fail here as they do on the skyline, but this picture
     * has a property that one doesn't: it is an island. So the grid comes from two passes that
     * never ask what colour anything is — a tolerant flood inwards from the frame, which stops at
     * hard edges and so captures everything that is *not* a building (the harbour and the road
     * network together, because they meet at the shoreline); and then the island's own silhouette,
     * taken from the pixels the flood could not reach, to say which of that is street and which is
     * sea.
     *
     * The result is a real street grid: avenues and cross-streets walkable, blocks solid, harbour
     * solid, bridges crossing. The shoreline is blocky where the silhouette overshoots at the
     * corners, which is a tile or two of walkable water and not worth a cleverer hull.
     */
    private static function nycIsland(): array
    {
        $tiles = [
                '................................................',
                '................................................',
                '....#.......#....####...##.#####..##.##.....##..',
                '....#.......##..########...#####.##..##......#..',
                '....#....##..#...#######...###...###.##.........',
                '...###...##......####......###.#.###............',
                '..~.....###......####......#####.###............',
                '.........##..##..####.###..#####.###..#......~..',
                '.........##.###..###..###....##..###............',
                '..~.#...###.###..####.####.#####.###...##.#.....',
                '.......##........####.####.#####.###...##.#.....',
                '......###.#####..####........###.###......#.....',
                '..~....##.#####..###..##......#.................',
                '.......##.#####..###..###.....#...#..####.......',
                '.......##.#####..###..##...#.#....#..####...#...',
                '........#..####..###..###..##...###..####...#...',
                '..#.....##.................#....###..####...#...',
                '..~.........##...###..#..#...#..###.#####.......',
                '............##...###.#####...#..###.#####.......',
                '.........#.###...###.#####......................',
                '..~~..#....###...###.#####..........#.....#..#..',
                '......#..#.#.#...###..#..#..###.#######.........',
                '..~~.#####....#.......#..######.#########.......',
                '......###.............##.######.########....~~..',
                '..~...###..##....###..##.######..######....##~..',
                '..~~~.####.#..#..###..##.######.########.....~..',
                '..~...###..#..........#.............###...#..~..',
                '..~~..##...........................#.....#......',
                '..~~~~.###...#..#..#..#.....##..#...............',
                '..~~~~.............#..............~.~....~.~....',
                '................................................',
                '................................................',
        ];

        return [
            'label' => 'NYC island, night',
            'description' => 'The whole island after dark: bridges, piers and the harbour around it',
            'name' => 'NYC island',
            'width' => 48,
            'height' => 32,
            'tiles' => $tiles,
            'backdrops' => [['key' => 'nyc-island', 'x' => 0, 'y' => 0, 'w' => 48, 'h' => 32]],
            'zones' => [],
            'objects' => [],
            'portals' => [],
            'spawn' => ['x' => 16, 'y' => 16],
        ];
    }

    /** Four walls and nothing in them, for somebody who'd rather draw their own. 24×16. */
    /**
     * A cinema: raked seating, a lit screen, and the exit at the back. 43x24.
     *
     * ## Drawn, not tiled
     *
     * This is a {@see Backdrops} map. The room is one piece of isometric pixel art and the tile
     * grid underneath it is **collision only** — never painted. That is the only way a room at
     * this fidelity fits into a system built for sixteen-pixel rectangles, and it changes nothing
     * else: proximity audio, pathing, doors, zones and the games all read the same grid they
     * always did, because none of them ever asked what a tile looked like.
     *
     * ## Where the grid came from
     *
     * Derived from the artwork rather than authored beside it, which is what keeps the two in
     * step. The image is 1376x768 and a tile is 32px, so it is exactly 43x24 with no resampling.
     * Every tile was classified by what the picture actually contains: transparent outside the
     * theatre's diamond becomes void, the dark perimeter becomes wall, and the floor is what is
     * left. Two corrections were needed on top of that, and both are the kind a colour test
     * cannot make:
     *
     *   - **Nothing above the stage line is floor.** The red curtains beside the screen read as
     *     floor on colour alone, and walking up into them would put people inside the back wall.
     *   - **Only tiles the floor can reach are floor.** Shadows enclosed inside the seating read
     *     as solid and would otherwise have become pillars scattered across the auditorium.
     *
     * The seats themselves are walkable. They are painted into the artwork rather than being
     * furniture, and an axis-aligned grid over isometric seating cannot trace their rows without
     * being visibly wrong somewhere — so the whole house floor is open, which is also how you get
     * a room where thirty people can spread out and watch something.
     *
     * ## The screen
     *
     * A `screens` surface over the painted one, sheared to match. The artwork is isometric, so
     * what is drawn is a parallelogram at the usual 2:1 slope: eight tiles across, four down, and
     * the right edge four tiles higher than the left. A share drawn square onto that sits
     * visibly crooked, which is what `skew` exists to fix.
     */
    private static function movieTheatre(): array
    {
        return [
            'label' => 'Movie Theatre',
            'description' => 'An isometric auditorium: raked seating, and a screen that plays whatever the room shares',
            'name' => 'Movie Theatre',
            'width' => 43,
            'height' => 24,
            // Collision only — the artwork is what anybody sees. See the note above.
            'tiles' => [
                '                     #                     ',
                '                   #####                   ',
                '                 #########                 ',
                '               #############               ',
                '             #################             ',
                '           #####################           ',
                '         #########################         ',
                '       #############################       ',
                '     #####..####.....#################     ',
                '     #####..##...........##############    ',
                '     ######................#.##########    ',
                '     #######...................########    ',
                '     #######.......................####    ',
                '     #######..........................#    ',
                '     #####.......................##...#    ',
                '     ###.......................#######     ',
                '       ##................##...######       ',
                '         ##.............##########         ',
                '           ##..........#########           ',
                '             #............####             ',
                '               #..........##               ',
                '                 #.......#                 ',
                '                   #...#                   ',
                '                     #                     ',
            ],
            'backdrops' => [
                ['key' => 'movie-theatre', 'x' => 0, 'y' => 0, 'w' => 43, 'h' => 24],
            ],
            /*
             * The screen, over the one in the picture and sheared onto it.
             *
             * Whatever anybody in the room shares plays here, and standing in the house offers to
             * watch it fullscreen. The televisions a room would otherwise need are not here:
             * there is nothing to press E on, because the screen is the room.
             */
            'screens' => [
                ['id' => 'the-screen', 'name' => 'The screen', 'x' => 11, 'y' => 7, 'w' => 8, 'h' => 4, 'skew' => -4],
            ],
            /*
             * The house, as a stage.
             *
             * A cinema is the one room where the *audience* wants to hear each other quietly and
             * whoever is presenting wants to be heard by everybody — which is exactly what a
             * stage zone does. Drawn over the front of the house rather than the whole of it, so
             * the back rows can talk among themselves without carrying.
             */
            'zones' => [
                ['id' => 'down-front', 'name' => 'Down the front', 'kind' => 'stage', 'x' => 10, 'y' => 8, 'w' => 12, 'h' => 4],
            ],
            /*
             * The seats — invisible, because they are already drawn.
             *
             * A backdrop map has no furniture on it: the room *is* the picture, and standing a
             * sixteen-pixel chair sprite on top of a painted one would look exactly as bad as it
             * sounds. But a cinema whose seats you cannot sit in is a room with a floor plan
             * instead of seats, so this is the missing half — the `seat` kind occupies a tile,
             * can be sat on, and draws nothing at all. See Decorations.
             *
             * Their positions are read off the artwork rather than laid out by hand: the seat
             * backs are a distinct red, so every tile that is mostly seat-red *within the seating
             * block* becomes one. The block bound is doing real work — the carpet at the front of
             * the house is the same colour and is not somewhere to sit — and so is the pass that
             * drops tiles with fewer than two neighbours, which removes flecks of curtain that
             * happened to match.
             *
             * They are not a grid, and shouldn't be. The rows run diagonally because the room is
             * drawn in perspective, and these follow them.
             */
            'objects' => self::objects([
                ['seat', 20, 9], ['seat', 21, 9], ['seat', 22, 9], ['seat', 23, 9],
                ['seat', 19, 10], ['seat', 20, 10], ['seat', 21, 10], ['seat', 22, 10], ['seat', 23, 10], ['seat', 24, 10], ['seat', 25, 10], ['seat', 26, 10],
                ['seat', 17, 11], ['seat', 18, 11], ['seat', 19, 11], ['seat', 20, 11], ['seat', 21, 11], ['seat', 22, 11], ['seat', 24, 11], ['seat', 25, 11], ['seat', 26, 11], ['seat', 27, 11], ['seat', 28, 11], ['seat', 29, 11],
                ['seat', 12, 12], ['seat', 15, 12], ['seat', 16, 12], ['seat', 17, 12], ['seat', 18, 12], ['seat', 19, 12], ['seat', 20, 12], ['seat', 21, 12], ['seat', 22, 12], ['seat', 23, 12], ['seat', 24, 12], ['seat', 25, 12], ['seat', 26, 12], ['seat', 27, 12], ['seat', 28, 12], ['seat', 29, 12], ['seat', 32, 12], ['seat', 33, 12], ['seat', 34, 12],
                ['seat', 12, 13], ['seat', 13, 13], ['seat', 14, 13], ['seat', 15, 13], ['seat', 16, 13], ['seat', 17, 13], ['seat', 18, 13], ['seat', 19, 13], ['seat', 20, 13], ['seat', 21, 13], ['seat', 22, 13], ['seat', 23, 13], ['seat', 24, 13], ['seat', 26, 13], ['seat', 27, 13], ['seat', 29, 13], ['seat', 33, 13], ['seat', 34, 13], ['seat', 35, 13], ['seat', 36, 13],
                ['seat', 11, 14], ['seat', 12, 14], ['seat', 13, 14], ['seat', 14, 14], ['seat', 16, 14], ['seat', 17, 14], ['seat', 18, 14], ['seat', 19, 14], ['seat', 20, 14], ['seat', 21, 14], ['seat', 22, 14], ['seat', 23, 14], ['seat', 24, 14], ['seat', 25, 14], ['seat', 27, 14], ['seat', 35, 14], ['seat', 36, 14],
                ['seat', 11, 15], ['seat', 22, 15], ['seat', 23, 15], ['seat', 25, 15],
                ['seat', 11, 16], ['seat', 14, 16], ['seat', 23, 16],
            ]),
            // The back of the house, by the steps — where you come in.
            'spawn' => ['x' => 21, 'y' => 20],
        ];
    }

    /**
     * A museum, drawn as a cutaway. 44x24.
     *
     * A {@see Backdrops} map like the cinema, and derived the same way — but the picture is a
     * genuinely harder shape and the result is honestly rougher, which is worth stating plainly.
     *
     * ## Why its collision is approximate
     *
     * Every other backdrop here shows *one floor*. This one is a cutaway: domes, glass roofs and
     * upper galleries are drawn above the halls, and the halls above the plaza. A tile grid has
     * one plane, so no arrangement of characters is faithful to a picture showing three storeys
     * at once — a wall on the second floor and the floor beneath it are the same square.
     *
     * Colour cannot separate them either: the whole building is pale stone, and the per-tile
     * brightness of its floors, walls and roofs all sit inside a band of about sixty levels.
     *
     * So the grid says something simpler and true: **the lower two-thirds is one open floor.**
     * Everything above the roofline is solid, the building's edge is walled so nobody walks off
     * it, and what is left is one connected hall of 474 tiles you can wander. You *can* walk
     * where an interior wall is drawn. That is a deliberate trade — a museum you can cross beats
     * a museum subdivided by walls that only half exist — and anyone wanting the galleries
     * separated can paint them in with the tile tool, which is exactly what it is for.
     *
     * ## The frames
     *
     * A handful along the gallery wall, as starting points rather than a finished hang. They are
     * empty: what goes in a frame is uploaded per server, by staff, through its own endpoint —
     * see the exhibits migration. Drag them onto the paintings you want and hang your own.
     */
    private static function metMuseum(): array
    {
        return [
            'label' => 'Museum',
            'description' => 'A cutaway museum whose paintings open full size — hang your own in the frames',
            'name' => 'Museum',
            'width' => 44,
            'height' => 24,
            'tiles' => [
                '                          #                 ',
                '                  #  ## ####                ',
                '          ##     ###########  ###           ',
                '          ####  #####################       ',
                '         ############################       ',
                '         #############################      ',
                '         #############################      ',
                '       ################################     ',
                '     ##................................#    ',
                '   ##...................................#   ',
                ' ##......................................## ',
                ' #.......................................#  ',
                ' #.......................................#  ',
                ' #.......................................#  ',
                ' #.......................................#  ',
                ' ##.......................................# ',
                '   ##....................................#  ',
                '     ##.................................#   ',
                '       ##.###............................#  ',
                '         #   ##...........................# ',
                '               ##..........###.............#',
                '                 ##......##   ##...........#',
                '                   ##..##       ####.......#',
                '                     ##             ########',
            ],
            'backdrops' => [
                ['key' => 'met-museum', 'x' => 0, 'y' => 0, 'w' => 44, 'h' => 24],
            ],
            /*
             * Frames along the top of the walkable floor, where the wall of paintings is drawn.
             *
             * Starting points, deliberately few. Placing forty by guesswork would mean forty
             * rectangles somebody has to drag off the wrong paintings before they can use any of
             * them, which is more work than drawing eight in the right places.
             */
            'exhibits' => [
                ['id' => 'a1', 'name' => 'Artwork 1', 'x' => 6, 'y' => 8, 'w' => 2, 'h' => 1],
                ['id' => 'a2', 'name' => 'Artwork 2', 'x' => 9, 'y' => 8, 'w' => 2, 'h' => 1],
                ['id' => 'a3', 'name' => 'Artwork 3', 'x' => 12, 'y' => 8, 'w' => 2, 'h' => 1],
                ['id' => 'a4', 'name' => 'Artwork 4', 'x' => 15, 'y' => 8, 'w' => 2, 'h' => 1],
                ['id' => 'a5', 'name' => 'Artwork 5', 'x' => 24, 'y' => 8, 'w' => 2, 'h' => 1],
                ['id' => 'a6', 'name' => 'Artwork 6', 'x' => 27, 'y' => 8, 'w' => 2, 'h' => 1],
                ['id' => 'a7', 'name' => 'Artwork 7', 'x' => 30, 'y' => 8, 'w' => 2, 'h' => 1],
                ['id' => 'a8', 'name' => 'Artwork 8', 'x' => 33, 'y' => 8, 'w' => 2, 'h' => 1],
            ],
            // The great hall, as a stage: a guide addressing a tour is heard across the museum,
            // and the rooms off it talk among themselves.
            'zones' => [
                ['id' => 'great-hall', 'name' => 'The great hall', 'kind' => 'stage', 'x' => 18, 'y' => 12, 'w' => 8, 'h' => 4],
            ],
            // Drawn room: nothing standing on it. See the cinema's note.
            'objects' => [],
            // The plaza, at the front.
            'spawn' => ['x' => 22, 'y' => 22],
        ];
    }

    /**
     * A rooftop screening under the Brooklyn Bridge. 44x24.
     *
     * The third drawn room, and the first in *perspective* rather than isometric — which changes
     * two things and nothing else.
     *
     * **The deck is a trapezoid, not a diamond.** So it is derived by brightness rather than by
     * shape: the asphalt and grass of the roof sit well under the sunset behind them, and the
     * largest dark region below the skyline is the roof. The bright patches left inside it — the
     * screen's footings, the lit railings — stay solid, which is what they are.
     *
     * **The screen is sheared the other way.** An isometric screen rises to the right; this one
     * is a billboard seen from below and to the left, so its top edge *falls* — hence a positive
     * `skew` where the cinema's is negative. It is a trapezoid in the artwork and a parallelogram
     * here, since a shear is what `skew` can express; the mismatch is a few pixels at the far
     * corner and the fields in the editor are there to nudge it.
     *
     * The seats are read off the picture like the cinema's: the deckchairs are vivid against a
     * roof that is deliberately not, so a saturation test finds them where a colour test would
     * not. They are invisible `seat` decorations — the chairs are already drawn.
     */
    private static function outdoorCinema(): array
    {
        return [
            'label' => 'Outdoor Cinema',
            'description' => 'A rooftop screening under the Brooklyn Bridge — deckchairs, and a screen that plays what the room shares',
            'name' => 'Outdoor Cinema',
            'width' => 44,
            'height' => 24,
            'tiles' => [
                '############################################',
                '############################################',
                '############################################',
                '############################################',
                '############################################',
                '############################################',
                '############################################',
                '############################################',
                '############################################',
                '############################################',
                '#...........########..........#######......#',
                '#....##.....######..............#####......#',
                '#..###......###...................###......#',
                '#...........#..............................#',
                '#.......#.............#....................#',
                '#..........................................#',
                '#..........................................#',
                '#..........................................#',
                '#..........................................#',
                '#..........................................#',
                '#..........................................#',
                '#..........................................#',
                '#..........................................#',
                '############################################',
            ],
            'backdrops' => [
                ['key' => 'brooklyn-bridge', 'x' => 0, 'y' => 0, 'w' => 44, 'h' => 24],
            ],
            /*
             * The big screen, over the painted one.
             *
             * Positive skew: a billboard seen from below on the left has a top edge that falls to
             * the right, where an isometric room's screen rises. Measured off the artwork — the
             * panel drops about 105 pixels across its 294, which is a shade over three tiles in
             * nine.
             */
            'screens' => [
                ['id' => 'the-screen', 'name' => 'The big screen', 'x' => 27, 'y' => 3, 'w' => 9, 'h' => 6, 'skew' => 3],
            ],
            /*
             * The front rows, as a stage.
             *
             * Whoever stands between the seating and the screen is introducing the film, and is
             * carried to the whole roof; everybody in the deckchairs talks to the people beside
             * them. The same arrangement the indoor cinema uses, for the same reason.
             */
            'zones' => [
                ['id' => 'down-front', 'name' => 'Down the front', 'kind' => 'stage', 'x' => 20, 'y' => 10, 'w' => 10, 'h' => 3],
            ],
            /*
             * The deckchairs — invisible, because they are already drawn.
             *
             * Read off the artwork by saturation: the chairs are vivid red, blue and green
             * against asphalt and dusk-lit grass that are deliberately not, so where the indoor
             * cinema needed a *colour* test this needs only a *vividness* one. The neighbour pass
             * that follows removes single tiles that happened to catch a lamp or a flower bed.
             */
            'objects' => self::objects([
                ['seat', 25, 10], ['seat', 26, 10],
                ['seat', 25, 11], ['seat', 26, 11], ['seat', 27, 11], ['seat', 28, 11],
                ['seat', 7, 12], ['seat', 8, 12], ['seat', 9, 12], ['seat', 10, 12], ['seat', 21, 12], ['seat', 22, 12], ['seat', 23, 12], ['seat', 24, 12], ['seat', 27, 12], ['seat', 28, 12], ['seat', 29, 12], ['seat', 30, 12],
                ['seat', 6, 13], ['seat', 7, 13], ['seat', 8, 13], ['seat', 9, 13], ['seat', 10, 13], ['seat', 21, 13], ['seat', 22, 13], ['seat', 23, 13], ['seat', 24, 13], ['seat', 25, 13], ['seat', 26, 13], ['seat', 29, 13], ['seat', 30, 13], ['seat', 31, 13], ['seat', 32, 13],
                ['seat', 6, 14], ['seat', 7, 14], ['seat', 9, 14], ['seat', 10, 14], ['seat', 17, 14], ['seat', 18, 14], ['seat', 19, 14], ['seat', 20, 14], ['seat', 21, 14], ['seat', 23, 14], ['seat', 24, 14], ['seat', 25, 14], ['seat', 26, 14], ['seat', 31, 14], ['seat', 32, 14], ['seat', 33, 14], ['seat', 34, 14],
                ['seat', 7, 15], ['seat', 8, 15], ['seat', 9, 15], ['seat', 17, 15], ['seat', 18, 15], ['seat', 19, 15], ['seat', 21, 15], ['seat', 22, 15], ['seat', 23, 15], ['seat', 24, 15], ['seat', 25, 15], ['seat', 28, 15], ['seat', 29, 15], ['seat', 30, 15], ['seat', 33, 15], ['seat', 34, 15], ['seat', 35, 15], ['seat', 36, 15],
                ['seat', 4, 16], ['seat', 5, 16], ['seat', 13, 16], ['seat', 14, 16], ['seat', 15, 16], ['seat', 16, 16], ['seat', 17, 16], ['seat', 19, 16], ['seat', 20, 16], ['seat', 21, 16], ['seat', 22, 16], ['seat', 23, 16], ['seat', 26, 16], ['seat', 27, 16], ['seat', 28, 16], ['seat', 29, 16], ['seat', 30, 16], ['seat', 31, 16], ['seat', 32, 16], ['seat', 35, 16], ['seat', 36, 16], ['seat', 37, 16],
                ['seat', 1, 17], ['seat', 2, 17], ['seat', 3, 17], ['seat', 4, 17], ['seat', 5, 17], ['seat', 11, 17], ['seat', 12, 17], ['seat', 13, 17], ['seat', 14, 17], ['seat', 15, 17], ['seat', 17, 17], ['seat', 18, 17], ['seat', 19, 17], ['seat', 20, 17], ['seat', 21, 17], ['seat', 24, 17], ['seat', 25, 17], ['seat', 26, 17], ['seat', 27, 17], ['seat', 28, 17], ['seat', 29, 17], ['seat', 30, 17], ['seat', 31, 17], ['seat', 32, 17], ['seat', 33, 17], ['seat', 34, 17],
                ['seat', 1, 18], ['seat', 2, 18], ['seat', 3, 18], ['seat', 9, 18], ['seat', 10, 18], ['seat', 11, 18], ['seat', 12, 18], ['seat', 13, 18], ['seat', 14, 18], ['seat', 15, 18], ['seat', 16, 18], ['seat', 17, 18], ['seat', 18, 18], ['seat', 22, 18], ['seat', 23, 18], ['seat', 24, 18], ['seat', 26, 18], ['seat', 27, 18], ['seat', 28, 18], ['seat', 29, 18], ['seat', 30, 18], ['seat', 31, 18], ['seat', 32, 18], ['seat', 33, 18], ['seat', 34, 18],
                ['seat', 7, 19], ['seat', 8, 19], ['seat', 9, 19], ['seat', 10, 19], ['seat', 11, 19], ['seat', 12, 19], ['seat', 13, 19], ['seat', 14, 19], ['seat', 21, 19], ['seat', 22, 19], ['seat', 23, 19], ['seat', 24, 19], ['seat', 25, 19], ['seat', 26, 19], ['seat', 28, 19], ['seat', 29, 19], ['seat', 30, 19], ['seat', 31, 19], ['seat', 32, 19],
                ['seat', 5, 20], ['seat', 6, 20], ['seat', 7, 20], ['seat', 8, 20], ['seat', 9, 20], ['seat', 10, 20], ['seat', 11, 20], ['seat', 12, 20], ['seat', 13, 20], ['seat', 14, 20], ['seat', 19, 20], ['seat', 20, 20], ['seat', 21, 20], ['seat', 22, 20], ['seat', 23, 20], ['seat', 24, 20], ['seat', 25, 20], ['seat', 26, 20], ['seat', 27, 20], ['seat', 28, 20], ['seat', 29, 20], ['seat', 30, 20], ['seat', 31, 20],
                ['seat', 4, 21], ['seat', 5, 21], ['seat', 6, 21], ['seat', 7, 21], ['seat', 8, 21], ['seat', 9, 21], ['seat', 10, 21], ['seat', 11, 21], ['seat', 12, 21], ['seat', 17, 21], ['seat', 18, 21], ['seat', 19, 21], ['seat', 20, 21], ['seat', 21, 21], ['seat', 22, 21], ['seat', 23, 21], ['seat', 24, 21], ['seat', 25, 21], ['seat', 26, 21], ['seat', 28, 21], ['seat', 29, 21],
                ['seat', 4, 22], ['seat', 5, 22], ['seat', 6, 22], ['seat', 7, 22], ['seat', 8, 22], ['seat', 9, 22], ['seat', 10, 22], ['seat', 16, 22], ['seat', 17, 22], ['seat', 18, 22], ['seat', 19, 22], ['seat', 20, 22], ['seat', 22, 22], ['seat', 23, 22], ['seat', 24, 22], ['seat', 25, 22], ['seat', 26, 22],
            ]),
            // The back of the roof, where the stairs come up.
            'spawn' => ['x' => 22, 'y' => 21],
        ];
    }

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
