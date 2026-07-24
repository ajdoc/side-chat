<?php

namespace App\Support\SideSpace;

/**
 * The rooms a Side Space can be born as.
 *
 * Kept on the server, not in the browser, because creating a channel has to *seed a map* — and
 * a client that supplied its own would be handing us geometry to trust. The creation page reads
 * this list to draw the picker; the create endpoint reads it to build the room. One source.
 *
 * A preset is a complete map: the same width/height/tiles/zones/spawn a saved one has, so
 * seeding is a copy and nothing downstream can tell a preset room from an edited one. Which is
 * the point — you pick a starting point, not a template you're stuck inside.
 *
 * Tile characters, everywhere: `.` floor (walkable), `#` wall (solid), ` ` void (also solid, but
 * drawn as nothing — it's what's *outside* the room).
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
     *     spawn: array{x: int, y: int}
     * }>
     */
    public static function all(): array
    {
        return [
            'office' => self::office(),
            'lounge' => self::lounge(),
            'campfire' => self::campfire(),
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
     * two sealed ones for when it shouldn't. 30×20.
     */
    private static function office(): array
    {
        $tiles = [
            '##############################',
            '#............................#',
            '#............................#',
            '#....########....########....#',
            '#....#......#....#......#....#',
            '#....#......#....#......#....#',
            '#....#......#....#......#....#',
            '#....###..###....###..###....#',
            '#............................#',
            '#............................#',
            '#............................#',
            '#............................#',
            '#....#########################',
            '#....#.......................#',
            '#....#.......................#',
            '#............................#',
            '#............................#',
            '#............................#',
            '#............................#',
            '##############################',
        ];

        return [
            'label' => 'Office',
            'description' => 'An open floor with two closed meeting rooms',
            'name' => 'Office',
            'width' => 30,
            'height' => 20,
            'tiles' => $tiles,
            'zones' => [
                ['id' => 'meet-a', 'name' => 'Meeting room A', 'kind' => 'private', 'x' => 6, 'y' => 4, 'w' => 6, 'h' => 3],
                ['id' => 'meet-b', 'name' => 'Meeting room B', 'kind' => 'private', 'x' => 18, 'y' => 4, 'w' => 6, 'h' => 3],
            ],
            'spawn' => ['x' => 15, 'y' => 10],
        ];
    }

    /**
     * Four tables in an open room — somewhere to hang about rather than to meet. 24×16.
     *
     * The tables are solid blocks with floor all the way round, and each zone is the ring of
     * floor *around* one. So a zone here is where you sit, not what you sit at — which is why a
     * zone is allowed to contain solid tiles and only has to contain somewhere to stand.
     */
    private static function lounge(): array
    {
        $tiles = [
            '########################',
            '#......................#',
            '#......................#',
            '#......................#',
            '#....##..........##....#',
            '#....##..........##....#',
            '#......................#',
            '#......................#',
            '#......................#',
            '#......................#',
            '#....##..........##....#',
            '#....##..........##....#',
            '#......................#',
            '#......................#',
            '#......................#',
            '########################',
        ];

        return [
            'label' => 'Lounge',
            'description' => 'Four tables in an open room, each its own private corner',
            'name' => 'Lounge',
            'width' => 24,
            'height' => 16,
            'tiles' => $tiles,
            'zones' => [
                ['id' => 'table-nw', 'name' => 'North-west table', 'kind' => 'private', 'x' => 4, 'y' => 3, 'w' => 4, 'h' => 4],
                ['id' => 'table-ne', 'name' => 'North-east table', 'kind' => 'private', 'x' => 16, 'y' => 3, 'w' => 4, 'h' => 4],
                ['id' => 'table-sw', 'name' => 'South-west table', 'kind' => 'private', 'x' => 4, 'y' => 9, 'w' => 4, 'h' => 4],
                ['id' => 'table-se', 'name' => 'South-east table', 'kind' => 'private', 'x' => 16, 'y' => 9, 'w' => 4, 'h' => 4],
            ],
            'spawn' => ['x' => 12, 'y' => 8],
        ];
    }

    /**
     * One round room with a fire in the middle. No zones at all — everybody in one circle, and
     * distance is the only thing between you. 20×20.
     */
    private static function campfire(): array
    {
        $tiles = [
            '       ######       ',
            '     ##......##     ',
            '    #..........#    ',
            '   #............#   ',
            '  #..............#  ',
            ' #................# ',
            ' #................# ',
            '#..................#',
            '#........##........#',
            '#........##........#',
            '#........##........#',
            '#........##........#',
            '#..................#',
            ' #................# ',
            ' #................# ',
            '  #..............#  ',
            '   #............#   ',
            '    #..........#    ',
            '     ##......##     ',
            '       ######       ',
        ];

        return [
            'label' => 'Campfire',
            'description' => 'One round room with a fire in the middle — no private corners',
            'name' => 'Campfire',
            'width' => 20,
            'height' => 20,
            'tiles' => $tiles,
            'zones' => [],
            'spawn' => ['x' => 10, 'y' => 14],
        ];
    }

    /** Four walls and nothing in them, for somebody who'd rather draw their own. 24×16. */
    private static function blank(): array
    {
        $tiles = array_map(
            fn (int $row) => $row === 0 || $row === 15
                ? str_repeat('#', 24)
                : '#'.str_repeat('.', 22).'#',
            range(0, 15),
        );

        return [
            'label' => 'Blank',
            'description' => 'An empty room to build yourself in the editor',
            'name' => 'Blank',
            'width' => 24,
            'height' => 16,
            'tiles' => $tiles,
            'zones' => [],
            'spawn' => ['x' => 12, 'y' => 8],
        ];
    }
}
