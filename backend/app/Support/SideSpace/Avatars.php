<?php

namespace App\Support\SideSpace;

/**
 * How you look when you're standing in a room, and what's following you around.
 *
 * Deliberately a short list of *named* options rather than free-form values. Two reasons, and
 * only one of them is validation:
 *
 *   1. Every client draws these sprites itself, from the layers in `lib/spaceAvatar.ts`. A hair
 *      style nobody has artwork for is a bald person; a colour outside the palette is somebody
 *      wearing a hue that fights every other sprite on screen. The set of things that can be
 *      *drawn* is finite, so the set of things that can be *stored* had better be the same one.
 *   2. It keeps the room looking like one game. A palette of eight hair colours chosen to sit
 *      together is worth more to a room full of people than a colour picker is to any one of
 *      them.
 *
 * The pets are original designs in the idiom of a starter trio — a leafy one, a fiery one and a
 * watery one, twice over, for the two regions' worth of them people expect. Same rule as the
 * trainer sprite: the *style* is borrowed, the creatures are not.
 */
final class Avatars
{
    /**
     * Body silhouettes. Small differences at 16×16, but they're the ones people read first —
     * `feminine` wears a skirt, which is the clearest way to read as female at this size.
     */
    public const BODIES = ['slim', 'sturdy', 'feminine'];

    public const HAIR = ['short', 'bob', 'long', 'ponytail', 'buzz', 'curly', 'spiky', 'cap'];

    public const HAIR_COLORS = ['black', 'brown', 'blonde', 'auburn', 'ash', 'blue', 'pink', 'green'];

    public const SKINS = ['porcelain', 'fair', 'olive', 'tan', 'brown', 'deep'];

    /** The shirt. `auto` keeps the colour derived from your user id, which is the old behaviour. */
    public const OUTFITS = ['auto', 'red', 'orange', 'yellow', 'green', 'teal', 'blue', 'indigo', 'violet', 'pink', 'slate'];

    /**
     * The starters, keyed by what a saved pet stores.
     *
     * @return array<string, array{label: string, element: string, region: string}>
     */
    public static function pets(): array
    {
        return [
            // The first three: sturdy, familiar shapes.
            'leafling' => ['label' => 'Leafling', 'element' => 'grass', 'region' => 'first'],
            'emberpup' => ['label' => 'Emberpup', 'element' => 'fire', 'region' => 'first'],
            'shellow' => ['label' => 'Shellow', 'element' => 'water', 'region' => 'first'],
            // The second three: lighter, rounder, a shade sillier.
            'sprigling' => ['label' => 'Sprigling', 'element' => 'grass', 'region' => 'second'],
            'cinderkit' => ['label' => 'Cinderkit', 'element' => 'fire', 'region' => 'second'],
            'snapling' => ['label' => 'Snapling', 'element' => 'water', 'region' => 'second'],
        ];
    }

    /** @return array<int, string> */
    public static function petKeys(): array
    {
        return array_keys(self::pets());
    }

    /**
     * What somebody looks like before they've chosen anything.
     *
     * `auto` for the shirt keeps every existing room exactly as it was: the colour still comes
     * from the user id, so nobody's sprite changes colour the day this ships.
     *
     * @return array{body: string, hair: string, hair_color: string, skin: string, outfit: string}
     */
    public static function defaultLook(): array
    {
        return [
            'body' => 'slim',
            'hair' => 'short',
            'hair_color' => 'brown',
            'skin' => 'fair',
            'outfit' => 'auto',
        ];
    }

    /**
     * A stored look, filled in and stripped of anything we don't recognise.
     *
     * Rows written before a new option existed, and rows written by a client that skipped
     * validation, both come out of here as something drawable. The alternative is a sprite that
     * renders as a hole because one key of five was a value nobody has artwork for.
     *
     * @param  array<string, mixed>|null  $look
     * @return array{body: string, hair: string, hair_color: string, skin: string, outfit: string}
     */
    public static function normaliseLook(?array $look): array
    {
        $default = self::defaultLook();

        $pick = fn (string $key, array $allowed): string => in_array($look[$key] ?? null, $allowed, true)
            ? (string) $look[$key]
            : $default[$key];

        return [
            'body' => $pick('body', self::BODIES),
            'hair' => $pick('hair', self::HAIR),
            'hair_color' => $pick('hair_color', self::HAIR_COLORS),
            'skin' => $pick('skin', self::SKINS),
            'outfit' => $pick('outfit', self::OUTFITS),
        ];
    }
}
