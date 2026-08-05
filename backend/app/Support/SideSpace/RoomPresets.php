<?php

namespace App\Support\SideSpace;

/**
 * The ways a **room** can be furnished — as distinct from the ways a **map** can be built.
 *
 * The two are easy to confuse and are not the same thing at all:
 *
 *   - a {@see MapPresets} entry is a whole Side Space. It has a size, walls, an entrance, and
 *     replaces everything. You pick one when you create the channel.
 *   - a room preset, here, is what goes *inside a rectangle you dragged on an existing map*.
 *     No size of its own that matters, no walls, no entrance — a floor and some furniture,
 *     stamped into a zone that already exists in a map that already exists.
 *
 * So this is what turns "drag out a room" into "drag out a *throne room*", which is the whole
 * feature: a map can now have a Westeros corner, a hobbit corner and a New York corner in it,
 * without any of them having been the map you started from.
 *
 * ## How a fixed layout fits a rectangle of any size
 *
 * A zone is whatever you dragged, and these are laid out at a natural size. Rather than
 * scaling (which would put a couch through a wall) or cropping (which would lose half the
 * room), each piece is **anchored to whichever edge it was nearest** when it was authored: a
 * bookshelf against the top wall stays against the top wall, a rug in the middle drifts with
 * the middle, and a lamp in the bottom-right corner stays in the bottom-right corner. The
 * client does the arithmetic — see `anchorObjects` in the editor — because it's the thing with
 * the zone and the collision rules in front of it.
 *
 * The upshot is that a room three tiles bigger than natural comes out looking deliberate, and
 * one that's smaller quietly drops whatever no longer fits rather than refusing to be a room.
 *
 * Kinds are {@see Decorations} keys and the floor is a {@see Tiles} character; nothing here
 * invents a vocabulary of its own.
 */
final class RoomPresets
{
    /**
     * Every room preset, keyed the way the API names it.
     *
     * `empty` leads because it's the default and because it's the honest description of what
     * dragging a room used to do — the list is a choice between "a room" and "a room that is
     * already somewhere", and hiding the old behaviour inside a picker would be worse than
     * naming it.
     *
     * @return array<string, array{
     *     label: string,
     *     description: string,
     *     floor: string,
     *     w: int,
     *     h: int,
     *     objects: array<int, array{kind: string, x: int, y: int, facing?: string}>
     * }>
     */
    public static function all(): array
    {
        return [
            'empty' => [
                'label' => 'Empty',
                'description' => 'Bare floor — furnish it yourself',
                // The neutral indoor tile, which is what an unfurnished room has always been.
                'floor' => Tiles::FLOOR,
                'w' => 6,
                'h' => 5,
                'objects' => [],
            ],

            'throne-room' => [
                'label' => 'Throne room',
                'description' => 'A throne of swords on boards, braziers and pillars flanking it',
                'floor' => Tiles::CARPET,
                'w' => 9,
                'h' => 7,
                'objects' => self::objects([
                    // The seat, dead centre against the back wall, with its fires either side.
                    ['throne', 3, 0],
                    ['torch', 2, 0], ['torch', 6, 0],
                    ['statue', 0, 0], ['statue', 8, 0],
                    // The approach: pillars down both sides, so the middle reads as an aisle.
                    ['pillar', 0, 3], ['pillar', 8, 3],
                    ['torch', 0, 5], ['torch', 8, 5],
                    ['bench', 1, 6], ['bench', 6, 6],
                ]),
            ],

            'green-hall' => [
                'label' => 'Green hall',
                'description' => 'A long table with benches, a hearth and shelves of records',
                'floor' => Tiles::WOOD,
                'w' => 9,
                'h' => 7,
                'objects' => self::objects([
                    // The table down the middle: two tables, benches on both long sides.
                    ['desk', 3, 2], ['desk', 5, 2],
                    ['bench', 3, 1], ['bench', 5, 1],
                    ['bench', 3, 3], ['bench', 5, 3],
                    // The hearth end, and the end everything is written down at.
                    ['campfire', 0, 0], ['barrel', 0, 2], ['crate', 0, 3],
                    ['bookshelf', 8, 0], ['lectern', 8, 2], ['filecabinet', 8, 3],
                    ['plant', 0, 6], ['plant', 8, 6], ['speaker', 4, 6],
                ]),
            ],

            'sleep-temple' => [
                'label' => 'Sleep temple',
                'description' => 'Pews facing a candlelit stage, with the stacks either side',
                'floor' => Tiles::CARPET,
                'w' => 9,
                'h' => 8,
                'objects' => self::objects([
                    // The stage: the effigies, the stacks, and the book you read from.
                    ['statue', 3, 0], ['statue', 5, 0],
                    ['speaker', 0, 0], ['speaker', 8, 0],
                    ['lectern', 4, 1],
                    ['torch', 1, 0], ['torch', 7, 0],
                    ['campfire', 1, 2], ['campfire', 7, 2],
                    // The pews: two rows either side of a centre aisle.
                    ['bench', 1, 4], ['bench', 6, 4],
                    ['bench', 1, 6], ['bench', 6, 6],
                    ['pillar', 0, 7], ['pillar', 8, 7],
                ]),
            ],

            'espurr-den' => [
                'label' => 'Espurr den',
                'description' => 'Plushes, a sofa, a telly and something to play on',
                'floor' => Tiles::CARPET,
                'w' => 8,
                'h' => 6,
                'objects' => self::objects([
                    // The pile, along the back.
                    ['plush', 0, 0], ['plush_vessel', 2, 0], ['plush_pickachu', 4, 0],
                    ['plush', 7, 0],
                    // Somewhere to sit and something to watch.
                    ['tv', 3, 2], ['rug', 3, 4], ['couch', 3, 5],
                    ['arcade', 0, 3], ['racer', 7, 3],
                    ['lamp', 0, 5], ['plush', 7, 5],
                ]),
            ],

            'new-york' => [
                'label' => 'New York diner',
                'description' => 'A counter of stools, booths by the wall and the telly on',
                'floor' => Tiles::WOOD,
                'w' => 9,
                'h' => 6,
                'objects' => self::objects([
                    // The counter: a run of tables with the stools pulled up in front of it.
                    ['desk', 1, 0], ['desk', 3, 0], ['desk', 5, 0],
                    ['stool', 1, 1], ['stool', 3, 1], ['stool', 5, 1], ['stool', 7, 1],
                    ['fridge', 8, 0], ['tv', 0, 0],
                    // The booths along the far wall, and what makes it a diner rather than a café.
                    ['bench', 0, 4], ['bench', 5, 4],
                    ['desk', 2, 4], ['desk', 7, 4],
                    ['speaker', 0, 5], ['plant', 8, 5], ['watercooler', 8, 3],
                ]),
            ],
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * One preset by key, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Turn a compact `[kind, x, y]` list into the shape the API hands over.
     *
     * No ids, unlike {@see MapPresets::objects()} — a room preset is stamped into a map that
     * already has furniture in it, so the ids have to be minted against *that* map's set and
     * only the client applying it knows what's already taken.
     *
     * @param  array<int, array{0: string, 1: int, 2: int, 3?: string}>  $list
     * @return array<int, array{kind: string, x: int, y: int, facing?: string}>
     */
    private static function objects(array $list): array
    {
        return array_map(
            fn (array $item) => [
                'kind' => $item[0],
                'x' => $item[1],
                'y' => $item[2],
                ...(isset($item[3]) ? ['facing' => $item[3]] : []),
            ],
            $list,
        );
    }
}
