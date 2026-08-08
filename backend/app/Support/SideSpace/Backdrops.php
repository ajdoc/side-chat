<?php

namespace App\Support\SideSpace;

/**
 * Whole-map artwork, drawn under everything instead of the tile grid.
 *
 * Every Side Space until now drew its ground from {@see Tiles} — a character per square, painted
 * by a hundred rectangles of code. That is the right way to hold a room somebody *builds*: it
 * diffs, it recolours, it costs nothing to store, and a room made of it can be edited square by
 * square by the person standing in it.
 *
 * It is the wrong way to hold a city somebody *drew*. A skyline, a harbour and a park at the
 * fidelity of real pixel art is not a hundred rectangles, it's a hundred thousand, and no
 * character grid gets there. So a map may instead name a backdrop: one image, drawn once under
 * all the sprites, covering exactly the map's width and height in tiles.
 *
 * ## The tiles do not go away
 *
 * They stop being *scenery* and become nothing but **collision**. A backdrop map still has a full
 * grid of characters underneath it, and that grid is still what decides where you can walk, what
 * a zone contains and where a door may go — it is simply never painted. This matters more than it
 * sounds: it means a backdrop is a change to how a room is *drawn* and to nothing else. Proximity
 * audio, pathing, the editor, the games and every existing rule work unchanged, because none of
 * them ever asked what a tile looked like.
 *
 * ## Why a key and not a URL
 *
 * A map is user-authored and any member may save one. A stored URL would therefore be an
 * arbitrary string, chosen by a member, that every other browser in the room is told to fetch —
 * which is a request-forgery and a tracking pixel wearing a hat. So a map stores a **key** into
 * this list, the server rejects anything else, and the artwork itself ships with the app.
 *
 * The browser has the same list in `lib/spaceBackdrops.ts` and resolves the key to a path, in
 * exactly the way it mirrors the tile alphabet and the furniture catalogue.
 */
final class Backdrops
{
    /**
     * Every backdrop, keyed by the value a saved map may use.
     *
     * `tiles` is the size the artwork was cut for. It is advisory rather than enforced — a
     * backdrop is stretched to whatever the map's own width and height are — but a map that
     * doesn't match its backdrop's grid has streets that no longer line up with the squares you
     * can walk on, so the presets stay honest and the editor warns.
     *
     * @return array<string, array{label: string, description: string, src: string, tiles: array{w: int, h: int}}>
     */
    public static function all(): array
    {
        return [
            /*
             * The key stays `gather-town` while the label reads New York, and that is deliberate:
             * a key is a stable identifier that saved maps and the artwork's own filename point
             * at, so renaming it would be a data migration and a file move to change a word
             * nobody sees. Labels are what people read; keys are what things are.
             */
            'gather-town' => [
                'label' => 'New York',
                'description' => 'A New York island: the Financial District, Times Square, SoHo and Central Park',
                // Under the client's /sprites root, like every other piece of real artwork.
                'src' => 'backdrops/gather-town.png',
                'tiles' => ['w' => 64, 'h' => 35],
            ],

            'nyc-street' => [
                'label' => 'NYC street',
                'description' => 'Four blocks around a fountain square: the bakery, the bookshop, the deli and the cinema',
                'src' => 'backdrops/nyc-street.png',
                // Cropped to 1152x768 so 36x24 lands on square 32px tiles. The full 1408 wide
                // would have squashed the artwork by a fifth to fit the grid asked for.
                'tiles' => ['w' => 36, 'h' => 24],
            ],

            'nyc-loft' => [
                'label' => 'NYC loft',
                'description' => 'A walk-up living room: brick, a worn couch, the fire escape window and the city beyond it',
                'src' => 'backdrops/nyc-loft.png',
                /*
                 * An interior, so its rim is *wall* rather than the walkable margin the outdoor
                 * backdrops carry — a cleared rim on a room is a cleared wall, and you would
                 * stroll out through the window.
                 *
                 * Small on purpose: this is a room you drop *into* a map, so it has to sit
                 * comfortably inside one rather than being a map in its own right. 14x8 also
                 * sets the scale — the couch is three tiles and a person is one, which is about
                 * right for a three-seater.
                 */
                'tiles' => ['w' => 14, 'h' => 8],
            ],
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * One backdrop by key, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
