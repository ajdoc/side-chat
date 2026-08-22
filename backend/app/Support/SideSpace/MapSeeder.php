<?php

namespace App\Support\SideSpace;

use App\Models\Channel;

/**
 * Giving a Side Space somewhere to stand.
 *
 * ## Why this is its own class
 *
 * Three things create spaces — creating a channel, converting one, and making a meeting — and
 * each of them grew its own copy of "look up the preset, write the map row". The third copy is
 * what found the bug: a meeting made its channel with `type => 'space'` already set and then
 * asked the conversion action to convert it, which correctly did nothing, so the room arrived
 * with no map at all and the client said **"could not load this space"**.
 *
 * The lesson isn't "call the right method", it's that *type* and *map* were two facts that had to
 * be set together and were being set by two different pieces of code. One seeder, called by
 * everything that can produce a space, is the shape where they can't drift apart again.
 */
final class MapSeeder
{
    /**
     * Make sure this channel has a map, without ever replacing one it already has.
     *
     * Idempotent on purpose: converting a channel away from `space` and back must not bulldoze
     * the furniture somebody placed, and a caller can't always know whether it's the first.
     */
    public static function ensure(Channel $channel, ?string $preset = null): void
    {
        if ($channel->spaceMap()->exists()) {
            return;
        }

        // Validation has already checked the key where a caller took one from a request; the
        // fallback is belt and braces against a path that skipped the FormRequest.
        $map = MapPresets::find($preset ?? 'blank') ?? MapPresets::find('blank');

        $channel->spaceMap()->create([
            'name' => $map['name'],
            'width' => $map['width'],
            'height' => $map['height'],
            'tiles' => $map['tiles'],
            'zones' => $map['zones'],
            'objects' => $map['objects'],
            'spawn' => $map['spawn'],
            // Only the artwork-backed presets carry any; everything else draws its tiles.
            'backdrops' => $map['backdrops'] ?? [],
            'portals' => $map['portals'] ?? [],
        ]);
    }
}
