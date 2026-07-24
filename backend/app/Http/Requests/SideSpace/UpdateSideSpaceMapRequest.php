<?php

namespace App\Http\Requests\SideSpace;

use App\Http\Requests\ServerOwnerRequest;
use App\Models\SideSpaceMap;
use Illuminate\Validation\Validator;

/**
 * Saving a Side Space's map. Owner only.
 *
 * Membership isn't enough here, for the same reason it isn't enough to delete a channel: this
 * doesn't add something alongside what everyone else has, it *replaces the room they are
 * standing in*. Painting a wall through somebody is not an edit you want any member to be able
 * to make. See {@see ServerOwnerRequest}.
 *
 * The scalar rules below only get as far as "these are numbers and strings of the right sort".
 * The part that matters — that the grid is exactly the size it claims, that its characters are
 * ones we know how to draw, that a zone is somewhere you can actually stand and spawn is
 * somewhere you can actually be put — is structural, and lives in {@see after()}. It's worth
 * the length: this is user-authored geometry that every other client in the room will render
 * and collide against, so a malformed map is everyone's problem, not just its author's.
 */
class UpdateSideSpaceMapRequest extends ServerOwnerRequest
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
            'zones.*.kind' => ['required', 'string', 'in:private'],
            'zones.*.x' => ['required', 'integer', 'min:0'],
            'zones.*.y' => ['required', 'integer', 'min:0'],
            'zones.*.w' => ['required', 'integer', 'min:1'],
            'zones.*.h' => ['required', 'integer', 'min:1'],

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

    /** A zone has to fit on the map, and has to contain somewhere to stand. */
    private function validateZones(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $width = (int) $this->input('width');
        $height = (int) $this->input('height');
        $tiles = (array) $this->input('tiles');
        $seen = [];

        foreach ((array) $this->input('zones', []) as $i => $zone) {
            if (in_array($zone['id'], $seen, true)) {
                $validator->errors()->add("zones.$i.id", 'Two zones share an id.');
            }
            $seen[] = $zone['id'];

            if ($zone['x'] + $zone['w'] > $width || $zone['y'] + $zone['h'] > $height) {
                $validator->errors()->add("zones.$i", "Zone \"{$zone['name']}\" runs off the map.");

                continue;
            }

            // A zone made entirely of wall is a room nobody can be inside, which would silently
            // do nothing — better to refuse it than to let it look like it worked.
            $standable = false;
            for ($y = $zone['y']; $y < $zone['y'] + $zone['h'] && ! $standable; $y++) {
                for ($x = $zone['x']; $x < $zone['x'] + $zone['w']; $x++) {
                    if (($tiles[$y][$x] ?? SideSpaceMap::WALL) === SideSpaceMap::FLOOR) {
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

    /** Spawn has to be a floor tile — it's where people are put when they have no position. */
    private function validateSpawn(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $tiles = (array) $this->input('tiles');
        $x = (int) $this->input('spawn.x');
        $y = (int) $this->input('spawn.y');

        if (($tiles[$y][$x] ?? SideSpaceMap::WALL) !== SideSpaceMap::FLOOR) {
            $validator->errors()->add('spawn', 'The entrance has to be on a floor tile.');
        }
    }
}
