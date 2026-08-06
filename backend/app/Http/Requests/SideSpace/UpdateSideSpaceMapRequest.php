<?php

namespace App\Http\Requests\SideSpace;

use App\Http\Requests\MemberRequest;
use App\Models\SideSpaceMap;
use App\Support\SideSpace\Decorations;
use App\Support\SideSpace\Tiles;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Saving a Side Space's map. Any member of the channel's server.
 *
 * This used to be owner-only, on the grounds that rebuilding the room replaces the place
 * everybody is standing in rather than adding something beside it. True, and not the point: a
 * Side Space is a room a group builds, and a room only one person may lay a floor in is one
 * person's room that the others visit. The furniture layer was already open to everybody
 * ({@see UpdateSpaceObjectsRequest}) and the walls being the owner's alone was the seam that
 * showed.
 *
 * What keeps a shared room habitable is not the gate but the *rules*, and those are all in
 * {@see after()} and unchanged: the grid is the size it claims, spawn is somewhere you can
 * stand, a zone is somewhere reachable. A member cannot save a room that traps anyone, because
 * nobody can. Vandalism is left where the rest of the app leaves it — visible, attributed
 * (`updated_by`), and undone by editing it back.
 *
 * The scalar rules below only get as far as "these are numbers and strings of the right sort".
 * The part that matters — that the grid is exactly the size it claims, that its characters are
 * ones we know how to draw, that a zone is somewhere you can actually stand and spawn is
 * somewhere you can actually be put — is structural, and lives in {@see after()}. It's worth
 * the length: this is user-authored geometry that every other client in the room will render
 * and collide against, so a malformed map is everyone's problem, not just its author's.
 */
class UpdateSideSpaceMapRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $min = SideSpaceMap::MIN_SIZE;
        $max = SideSpaceMap::MAX_SIZE;

        return [
            'name' => ['required', 'string', 'max:100'],
            'width' => ['required', 'integer', "min:$min", "max:$max"],
            'height' => ['required', 'integer', "min:$min", "max:$max"],

            'tiles' => ['required', 'array', "min:$min", "max:$max"],
            'tiles.*' => ['required', 'string'],

            'zones' => ['present', 'array', 'max:50'],
            'zones.*.id' => ['required', 'string', 'max:40'],
            'zones.*.name' => ['required', 'string', 'max:60'],
            // `private` seals a room; `stage` seals it too, but carries whoever is live on it to
            // the whole map. The client decides who that is (see liveSpeakers) — nothing about
            // it is stored, so this list is the whole of the server's interest in the matter.
            'zones.*.kind' => ['required', 'string', 'in:private,stage'],
            'zones.*.x' => ['required', 'integer', 'min:0'],
            'zones.*.y' => ['required', 'integer', 'min:0'],
            'zones.*.w' => ['required', 'integer', 'min:1'],
            'zones.*.h' => ['required', 'integer', 'min:1'],

            // Furniture. A decoration carries only what the author chose — where it goes, what
            // it is and which way it's turned. Its size, whether it's solid and what pressing E
            // on it opens are all read from the catalogue, so there is nothing here to lie about.
            // `sometimes`, unlike zones: a map saved without it is an unfurnished room rather
            // than a malformed request, which keeps every room built before furniture existed
            // saveable by a client that has never heard of it.
            'objects' => ['sometimes', 'array', 'max:'.Decorations::MAX_PER_MAP],
            'objects.*.id' => ['required', 'string', 'max:40'],
            'objects.*.kind' => ['required', 'string', Rule::in(Decorations::keys())],
            'objects.*.x' => ['required', 'integer', 'min:0'],
            'objects.*.y' => ['required', 'integer', 'min:0'],
            'objects.*.facing' => ['sometimes', 'string', Rule::in(Decorations::FACINGS)],

            'spawn' => ['required', 'array'],
            'spawn.x' => ['required', 'integer', 'min:0'],
            'spawn.y' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * The structural checks — everything that can only be judged with the whole payload in hand.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateGrid($validator),
            fn (Validator $validator) => $this->validateObjects($validator),
            fn (Validator $validator) => $this->validateZones($validator),
            fn (Validator $validator) => $this->validateSpawn($validator),
        ];
    }

    /** Exactly `height` rows of exactly `width` characters, all of them ones we can draw. */
    private function validateGrid(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $width = (int) $this->input('width');
        $height = (int) $this->input('height');
        $tiles = (array) $this->input('tiles');

        if (count($tiles) !== $height) {
            $validator->errors()->add('tiles', "The map must have exactly $height rows.");

            return;
        }

        foreach ($tiles as $y => $row) {
            if (! is_string($row) || mb_strlen($row) !== $width) {
                $validator->errors()->add("tiles.$y", "Row $y must be exactly $width characters.");

                continue;
            }

            if (strspn($row, SideSpaceMap::TILE_CHARS) !== strlen($row)) {
                $validator->errors()->add("tiles.$y", "Row $y contains a tile we don't recognise.");
            }
        }
    }

    /**
     * Furniture has to fit on the map, stand on something that exists, and not be inside other
     * furniture. The rules themselves live in {@see Decorations::problems} — shared with the
     * member-only decorate endpoint — because they're the same rules whether the tiles arrived
     * in this payload or were already stored.
     */
    private function validateObjects(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $problems = Decorations::problems(
            (array) $this->input('objects', []),
            (array) $this->input('tiles'),
            (int) $this->input('width'),
            (int) $this->input('height'),
        );

        foreach ($problems as $i => $message) {
            $validator->errors()->add("objects.$i", $message);
        }
    }

    /** A zone has to fit on the map, and has to contain somewhere to stand. */
    private function validateZones(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $width = (int) $this->input('width');
        $height = (int) $this->input('height');
        $seen = [];

        foreach ((array) $this->input('zones', []) as $i => $zone) {
            if (in_array($zone['id'], $seen, true)) {
                $validator->errors()->add("zones.$i.id", 'Two zones share an id.');
            }
            $seen[] = $zone['id'];

            if ($width < $zone['x'] + $zone['w'] || $height < $zone['y'] + $zone['h']) {
                $validator->errors()->add("zones.$i", "Zone \"{$zone['name']}\" runs off the map.");

                continue;
            }

            // A zone made entirely of wall is a room nobody can be inside, which would silently
            // do nothing — better to refuse it than to let it look like it worked. Furniture
            // counts: a meeting room packed wall-to-wall with desks is just as uninhabitable.
            $standable = false;
            for ($y = $zone['y']; $y < $zone['y'] + $zone['h'] && ! $standable; $y++) {
                for ($x = $zone['x']; $x < $zone['x'] + $zone['w']; $x++) {
                    if ($this->standable($x, $y)) {
                        $standable = true;
                        break;
                    }
                }
            }

            if (! $standable) {
                $validator->errors()->add("zones.$i", "Zone \"{$zone['name']}\" has nowhere to stand in it.");
            }
        }
    }

    /** Spawn has to be somewhere you can stand — it's where people are put with no position. */
    private function validateSpawn(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        if (! $this->standable((int) $this->input('spawn.x'), (int) $this->input('spawn.y'))) {
            $validator->errors()->add('spawn', 'The entrance has to be somewhere people can stand.');
        }
    }

    /**
     * Can somebody stand on this tile of the *payload* — ground walkable, nothing solid on it?
     *
     * The same question {@see SideSpaceMap::isWalkable} answers about a saved map, asked of a
     * map that isn't saved yet. Two implementations of one rule is a smell, but the alternative
     * is hydrating an unsaved model purely to validate it, and the rule is three lines.
     */
    private function standable(int $x, int $y): bool
    {
        $tiles = (array) $this->input('tiles');

        return Tiles::isWalkable($tiles[$y][$x] ?? Tiles::VOID)
            && ! Decorations::blocks((array) $this->input('objects', []), $x, $y);
    }
}
