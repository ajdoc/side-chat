<?php

namespace App\Http\Requests\SideSpace;

use App\Http\Requests\MemberRequest;
use App\Models\Channel;
use App\Models\SideSpaceMap;
use App\Support\SideSpace\Backdrops;
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
    /**
     * The payload's furniture as a tile set, built on first use — see {@see self::standable()}.
     *
     * @var array<string, true>|null
     */
    private ?array $solid = null;

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

            // Which way the room is drawn. `sometimes`, like `objects` and for the same reason:
            // a client that has never heard of projections saves a map without naming one, and
            // that map is flat — which is what it always was.
            'projection' => ['sometimes', 'string', Rule::in(SideSpaceMap::PROJECTIONS)],

            /*
             * Artwork drawn instead of the tile art, and where each piece of it goes.
             *
             * A key into a list the server owns, never a URL — a map is user-authored and any
             * member may save one, so a stored address is one member making every other browser
             * in the room fetch something. See Backdrops.
             *
             * A *list of placements* rather than one whole-map picture, which is what lets a map
             * be half hand-built room and half city: tiles outside every placement are drawn as
             * tile art. Capped, because each one is an image the whole room downloads.
             */
            'backdrops' => ['sometimes', 'nullable', 'array', 'max:'.SideSpaceMap::MAX_BACKDROPS],
            'backdrops.*.key' => ['required', 'string', Rule::in(Backdrops::keys())],
            'backdrops.*.x' => ['required', 'integer', 'min:-'.SideSpaceMap::MAX_SIZE, 'max:'.SideSpaceMap::MAX_SIZE],
            'backdrops.*.y' => ['required', 'integer', 'min:-'.SideSpaceMap::MAX_SIZE, 'max:'.SideSpaceMap::MAX_SIZE],
            'backdrops.*.w' => ['required', 'integer', 'min:1', 'max:'.SideSpaceMap::MAX_SIZE],
            'backdrops.*.h' => ['required', 'integer', 'min:1', 'max:'.SideSpaceMap::MAX_SIZE],

            /*
             * Doorways. A rectangle you walk into, plus where it goes.
             *
             * `sometimes`, like objects and artwork: a client that has never heard of portals
             * saves a map without them, and that map simply has none — rather than the save
             * being refused for a field it could not know about.
             */
            'portals' => ['sometimes', 'nullable', 'array', 'max:'.SideSpaceMap::MAX_PORTALS],
            'portals.*.id' => ['required', 'string', 'max:40'],
            'portals.*.name' => ['required', 'string', 'max:60'],
            'portals.*.x' => ['required', 'integer', 'min:0'],
            'portals.*.y' => ['required', 'integer', 'min:0'],
            'portals.*.w' => ['required', 'integer', 'min:1'],
            'portals.*.h' => ['required', 'integer', 'min:1'],
            // `point` goes somewhere else on this map; `room` goes to another Side Space.
            'portals.*.to.kind' => ['required', 'string', 'in:point,room'],
            'portals.*.to.x' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'portals.*.to.y' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'portals.*.to.channel_id' => ['required_if:portals.*.to.kind,room', 'nullable', 'integer'],

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
            fn (Validator $validator) => $this->validatePortals($validator),
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

    /**
     * A doorway has to be somewhere you can walk into, and has to lead somewhere real.
     *
     * The destination checks matter more than they look. A portal is walked into by *everybody*
     * in the room, so one pointing at a channel that isn't a Side Space, or that belongs to a
     * different server, is not a broken link — it is a door that takes people somewhere they may
     * have no business being. Whether a given person may actually go through is decided again
     * when they use it (see the controller); this only refuses doors that could never be right.
     */
    private function validatePortals(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $width = (int) $this->input('width');
        $height = (int) $this->input('height');
        $seen = [];

        foreach ((array) $this->input('portals', []) as $i => $portal) {
            if (in_array($portal['id'], $seen, true)) {
                $validator->errors()->add("portals.$i.id", 'Two doorways share an id.');
            }
            $seen[] = $portal['id'];

            if ($width < $portal['x'] + $portal['w'] || $height < $portal['y'] + $portal['h']) {
                $validator->errors()->add("portals.$i", "The doorway \"{$portal['name']}\" runs off the map.");

                continue;
            }

            // A doorway made entirely of wall is one nobody can ever step into, which would
            // silently do nothing — better to refuse it than let it look like it worked.
            $standable = false;
            for ($y = $portal['y']; $y < $portal['y'] + $portal['h'] && ! $standable; $y++) {
                for ($x = $portal['x']; $x < $portal['x'] + $portal['w']; $x++) {
                    if ($this->standable($x, $y)) {
                        $standable = true;
                        break;
                    }
                }
            }

            if (! $standable) {
                $validator->errors()->add("portals.$i", "The doorway \"{$portal['name']}\" has nowhere to stand in it.");
            }

            $to = $portal['to'] ?? [];

            if (($to['kind'] ?? null) === 'point') {
                $x = (int) ($to['x'] ?? -1);
                $y = (int) ($to['y'] ?? -1);

                if (! $this->standable($x, $y)) {
                    $validator->errors()->add("portals.$i.to", "The doorway \"{$portal['name']}\" comes out somewhere nobody can stand.");
                }

                continue;
            }

            $target = Channel::find($to['channel_id'] ?? 0);

            if (! $target || ! $target->isSpace()) {
                $validator->errors()->add("portals.$i.to", "The doorway \"{$portal['name']}\" leads to somewhere that isn't a Side Space.");

                continue;
            }

            // Same server only. A door out of the building is a different feature, and one that
            // would need to answer who is allowed to follow it.
            $here = $this->route('channel');

            if (! $here instanceof Channel || $target->server_id !== $here->server_id) {
                $validator->errors()->add("portals.$i.to", "The doorway \"{$portal['name']}\" leads outside this server.");
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
     *
     * Asked of every tile of every zone, so the furniture is folded into a tile set once. The
     * payload can't change under a request, which is what makes memoising it safe here.
     */
    private function standable(int $x, int $y): bool
    {
        $tiles = (array) $this->input('tiles');
        $this->solid ??= Decorations::solidTiles((array) $this->input('objects', []));

        return Tiles::isWalkable($tiles[$y][$x] ?? Tiles::VOID)
            && ! isset($this->solid["$x,$y"]);
    }
}
