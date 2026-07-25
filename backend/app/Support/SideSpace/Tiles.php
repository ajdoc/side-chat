<?php

namespace App\Support\SideSpace;

/**
 * The ground a Side Space is made of.
 *
 * A map is still a grid of single characters — that hasn't changed and shouldn't, because it is
 * what makes a room diff readably, validate in two lines and paint with a brush. What has
 * changed is how many characters there are: a room built out of "floor" and "wall" alone reads
 * as a floor plan, and the point of an overworld is that it reads as a *place*. Grass, water,
 * boards and a dirt path do more for that than any amount of shading of the same two tiles.
 *
 * Every character is declared once, here, with the only thing the server genuinely has to know
 * about it: whether you can stand on it. How it's *drawn* is the browser's business
 * (`lib/spaceTiles.ts`), and deliberately not mirrored here — the server never draws anything.
 * What is mirrored, and has to be, is walkability: the client collides against it sixty times a
 * second, and the server checks it when validating a saved room and when spawning somebody.
 */
final class Tiles
{
    /** Indoor floor — the neutral tile the old maps were made entirely of. */
    public const FLOOR = '.';

    /** Solid, and drawn with height. */
    public const WALL = '#';

    /** Outside the room. Solid, and drawn as nothing at all. */
    public const VOID = ' ';

    /** Short grass. Walkable. */
    public const GRASS = ',';

    /** Tall grass — walkable, and rustles when you wade through it. */
    public const TALL_GRASS = '"';

    /** Flowers in the grass. Walkable, purely decorative. */
    public const FLOWERS = '^';

    /** A worn dirt path. Walkable, and the thing that tells people where to walk. */
    public const PATH = '-';

    /** Floorboards. Walkable — an indoors that isn't the default indoors. */
    public const WOOD = '=';

    /** Carpet. Walkable. */
    public const CARPET = '%';

    /** Water. Solid: you can stand at the edge of it, not in it. */
    public const WATER = '~';

    /** A tree. Solid, and drawn a tile taller than its footprint. */
    public const TREE = 'T';

    /** Sand, for the edge of the water. Walkable. */
    public const SAND = ':';

    /**
     * Every character a saved row may contain.
     *
     * Order is irrelevant — this is fed to `strspn`, which only asks "is each character in this
     * set". Keep {@see walkable()} in step when adding to it.
     */
    public const ALL = self::FLOOR
        .self::WALL
        .self::VOID
        .self::GRASS
        .self::TALL_GRASS
        .self::FLOWERS
        .self::PATH
        .self::WOOD
        .self::CARPET
        .self::WATER
        .self::TREE
        .self::SAND;

    /**
     * The tiles you can stand on.
     *
     * Stated as the *allow* list rather than the block list on purpose: a new tile added to
     * {@see ALL} and forgotten here comes out solid, which is a room with an odd wall in it.
     * The other way round it would come out walkable, which is people walking through the sea.
     *
     * @return array<int, string>
     */
    public static function walkable(): array
    {
        return [
            self::FLOOR,
            self::GRASS,
            self::TALL_GRASS,
            self::FLOWERS,
            self::PATH,
            self::WOOD,
            self::CARPET,
            self::SAND,
        ];
    }

    /** Can somebody stand on this character? Unknown characters are solid. */
    public static function isWalkable(string $tile): bool
    {
        return in_array($tile, self::walkable(), true);
    }
}
