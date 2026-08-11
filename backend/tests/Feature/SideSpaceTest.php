<?php

use App\Events\SideSpaceMapUpdated;
use App\Events\SideSpaceSummoned;
use App\Events\VoiceStateUpdated;
use App\Models\Channel;
use App\Models\SideSpaceMap;
use App\Models\User;
use App\Models\VoiceParticipant;
use App\Models\Widget;
use App\Services\VoiceService;
use App\Support\SideSpace\Backdrops;
use App\Support\SideSpace\Decorations;
use App\Support\SideSpace\MapPresets;
use App\Support\SideSpace\RoomPresets;
use App\Support\SideSpace\Tiles;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;

/*
 * A Side Space is a channel you walk around in. Almost everything about it — messages, threads,
 * the call — is the existing stack unchanged, so what's worth testing here is the part that is
 * genuinely new: that a room gets built when the channel is, that any member may rebuild it,
 * that a malformed room is refused, and that where you were standing outlives your tab.
 */

// --- creation ---

it('seeds a map when a Side Space channel is created', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/channels", [
        'name' => 'the-office',
        'type' => 'space',
        'preset' => 'office',
    ])->assertCreated()->assertJsonPath('data.type', 'space');

    // The map hangs off the channel's General discussion, not the channel: a container holds
    // discussions, and each discussion is its own room.
    $channel = Channel::where('name', 'the-office')->sole();
    $map = $channel->discussions()->sole()->spaceMap;

    expect($map)->not->toBeNull()
        ->and($map->width)->toBe(30)
        ->and($map->height)->toBe(20)
        ->and($map->tiles)->toHaveCount(20)
        ->and($map->zones)->toHaveCount(2)
        // Seeded rooms have to satisfy the same rule saved ones do: you can stand where you
        // walk in.
        ->and($map->isWalkable($map->spawn['x'], $map->spawn['y']))->toBeTrue();
});

it('refuses to create a Side Space without a preset', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/channels", ['name' => 'roomless', 'type' => 'space'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('preset');

    // And nothing half-built is left behind — the seed shares the create's transaction.
    expect(Channel::where('name', 'roomless')->exists())->toBeFalse();
});

it('creates text and voice channels without a map', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/channels", ['name' => 'general', 'type' => 'text'])
        ->assertCreated();

    expect(Channel::where('name', 'general')->sole()->spaceMap)->toBeNull();
});

it('rejects an unknown channel type', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/channels", ['name' => 'weird', 'type' => 'hologram'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');
});

// --- reading the map ---

it('serves the map to any member of the channel', function () {
    [$owner, $server, $channel] = ownerWithSpaceChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    Passport::actingAs($member);

    $this->getJson("/api/channels/{$channel->id}/space/map")
        ->assertOk()
        ->assertJsonPath('data.width', 30)
        ->assertJsonPath('data.zones.0.name', 'Meeting room A');
});

it('forbids a non-member from reading the map', function () {
    [, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs(User::factory()->create());

    $this->getJson("/api/channels/{$channel->id}/space/map")->assertForbidden();
});

it('404s asking a text channel for a map', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->getJson("/api/channels/{$channel->id}/space/map")->assertNotFound();
});

it('lists the map presets, each one whole enough to load over a room', function () {
    Passport::actingAs(User::factory()->create());

    $res = $this->getJson('/api/space/map-presets')
        ->assertOk()
        ->assertJsonCount(count(MapPresets::keys()), 'data')
        ->assertJsonPath('data.0.key', 'office');

    // The editor loads a preset *over* the room it's editing, so every one has to arrive with
    // everything a room is — spawn included. A layout without its entrance would leave people
    // walking in wherever the old room's door happened to be.
    $res->assertJsonStructure([
        'data' => [['key', 'label', 'description', 'width', 'height', 'tiles', 'zones', 'objects', 'spawn' => ['x', 'y']]],
    ]);
});

it('lists the room presets, each one furnishable on the floor it asks for', function () {
    Passport::actingAs(User::factory()->create());

    $res = $this->getJson('/api/space/room-presets')
        ->assertOk()
        ->assertJsonCount(count(RoomPresets::keys()), 'data')
        // "Empty" leads, because it's the default and it's what dragging a room always did.
        ->assertJsonPath('data.0.key', 'empty');

    $res->assertJsonStructure([
        'data' => [['key', 'label', 'description', 'floor', 'w', 'h', 'objects']],
    ]);

    /*
     * The rules a room preset has to keep, checked here because a preset is stamped into
     * somebody's map by the client and a broken one is a room that quietly comes out with half
     * its furniture missing — which looks like a bug in the editor rather than a typo in a list.
     *
     * Three things, and they're the same three the map validator enforces on a saved room:
     * the kinds exist, they fit inside the layout they were authored at, and nothing solid
     * stands inside anything else solid.
     */
    foreach (RoomPresets::all() as $key => $preset) {
        expect(Tiles::isWalkable($preset['floor']))->toBeTrue("The '$key' room is paved with something nobody can stand on.");

        /*
         * A room that brings its own ground has to bring exactly as much of it as it claims, and
         * its artwork has to cover precisely that. Both are stamped straight into somebody's map:
         * a grid one row short lays a strip of the room they were standing on, and a backdrop
         * that doesn't match its grid is a picture whose walls are not where the walls are.
         */
        if (isset($preset['tiles'])) {
            expect($preset['tiles'])->toHaveCount($preset['h'], "The '$key' room's grid isn't {$preset['h']} rows.");

            foreach ($preset['tiles'] as $row => $line) {
                expect(mb_strlen($line))->toBe($preset['w'], "Row $row of the '$key' room isn't {$preset['w']} wide.");
            }

            foreach ($preset['backdrops'] ?? [] as $art) {
                expect(Backdrops::find($art['key']))->not->toBeNull("The '$key' room names artwork that doesn't exist.");
                expect([$art['w'], $art['h']])->toBe([$preset['w'], $preset['h']], "The '$key' room's artwork doesn't cover its grid.");
            }

            // And somewhere to stand, or it is a room that stamps a solid block into a map.
            $open = 0;
            foreach ($preset['tiles'] as $line) {
                foreach (str_split($line) as $tile) {
                    $open += Tiles::isWalkable($tile) ? 1 : 0;
                }
            }
            expect($open)->toBeGreaterThan(0, "The '$key' room has nowhere to stand in it.");
        }

        $taken = [];

        foreach ($preset['objects'] as $object) {
            $kind = Decorations::find($object['kind']);
            expect($kind)->not->toBeNull("The '$key' room uses furniture that doesn't exist: {$object['kind']}.");

            [$w, $h] = Decorations::size($object, $kind);

            expect($object['x'])->toBeGreaterThanOrEqual(0)
                ->and($object['y'])->toBeGreaterThanOrEqual(0)
                ->and($object['x'] + $w)->toBeLessThanOrEqual($preset['w'], "A {$object['kind']} hangs off the edge of the '$key' room.")
                ->and($object['y'] + $h)->toBeLessThanOrEqual($preset['h'], "A {$object['kind']} hangs off the edge of the '$key' room.");

            if (! $kind['solid']) {
                continue;
            }

            // Solid-on-solid only. A rug under a couch is the point, and always was.
            for ($dy = $object['y']; $dy < $object['y'] + $h; $dy++) {
                for ($dx = $object['x']; $dx < $object['x'] + $w; $dx++) {
                    expect($taken)->not->toContain("$dx,$dy", "Two solid pieces overlap in the '$key' room at $dx,$dy.");
                    $taken[] = "$dx,$dy";
                }
            }
        }
    }
});

// --- rebuilding the map ---

/** A minimal legal room: floor with a wall border. */
function validMapPayload(array $overrides = []): array
{
    $tiles = array_map(
        fn (int $row) => $row === 0 || $row === 9 ? str_repeat('#', 10) : '#'.str_repeat('.', 8).'#',
        range(0, 9),
    );

    return array_merge([
        'name' => 'Rebuilt',
        'width' => 10,
        'height' => 10,
        'tiles' => $tiles,
        'zones' => [],
        'spawn' => ['x' => 5, 'y' => 5],
    ], $overrides);
}

it('lets the server owner rebuild the room and tells everyone in it', function () {
    Event::fake([SideSpaceMapUpdated::class]);

    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload())
        ->assertOk()
        ->assertJsonPath('data.name', 'Rebuilt')
        ->assertJsonPath('data.width', 10);

    expect($channel->spaceMap()->sole()->height)->toBe(10);

    Event::assertDispatched(SideSpaceMapUpdated::class);
});

it('saves which way the room is drawn, and defaults it to flat', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    // A map nobody has ever chosen a projection for is flat — that's what every room built
    // before the isometric view existed is, and it must not change under them.
    $this->getJson("/api/channels/{$channel->id}/space/map")
        ->assertOk()
        ->assertJsonPath('data.projection', 'flat');

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload(['projection' => 'iso']))
        ->assertOk()
        ->assertJsonPath('data.projection', 'iso');

    expect($channel->spaceMap()->sole()->projection)->toBe('iso');

    // A save that names no projection leaves the room's own alone. Unlike the furniture, an
    // absent projection is "unchanged" rather than "the default" — an older client saving a
    // layout must not quietly flatten a room somebody built isometric.
    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload())
        ->assertOk()
        ->assertJsonPath('data.projection', 'iso');
});

it('saves backdrop artwork with the rectangle it covers, and lets it be taken off again', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    // A placement, not a whole-map key: the point of the rectangle is that a map can be part
    // hand-built room and part artwork.
    $placed = [['key' => 'gather-town', 'x' => 2, 'y' => 1, 'w' => 6, 'h' => 4]];

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload(['backdrops' => $placed]))
        ->assertOk()
        ->assertJsonPath('data.backdrops.0.key', 'gather-town')
        ->assertJsonPath('data.backdrops.0.x', 2)
        ->assertJsonPath('data.backdrops.0.w', 6);

    // A whole-map save carries the whole map, artwork included — so an empty list, or no list at
    // all, is a room with no artwork. This used to mean "leave it alone", and that made a
    // backdrop impossible to remove the moment the client stopped sending the field. See the
    // note in SideSpaceController::update.
    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload(['backdrops' => []]))
        ->assertOk()
        ->assertJsonCount(0, 'data.backdrops');

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload(['backdrops' => $placed]))
        ->assertOk()
        ->assertJsonCount(1, 'data.backdrops');

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload())
        ->assertOk()
        ->assertJsonCount(0, 'data.backdrops');
});

it('refuses backdrop artwork that is not one of ours', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    // The rule that matters: a map is user-authored and any member may save one, so a stored
    // path would be an address a member gets every other browser in the room to fetch.
    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload([
        'backdrops' => [['key' => 'https://example.com/tracker.png', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 4]],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('backdrops.0.key');
});

it('seeds a new channel with its preset\'s backdrop, and the artwork map is walkable throughout', function () {
    $preset = MapPresets::find('gather-town');

    $placement = $preset['backdrops'][0];

    expect($placement['key'])->toBe('gather-town')
        ->and(Backdrops::find($placement['key']))->not->toBeNull()
        // The placement has to cover the grid it was cut for, or the streets in the picture stop
        // lining up with the squares people can stand on.
        ->and([$placement['w'], $placement['h']])->toBe([$preset['width'], $preset['height']]);

    // The grid is machine-derived from the artwork, so the thing worth asserting is not any
    // particular tile but that the island is one place: four landmasses joined by four bridges
    // is four ways for a generated map to be silently cut in half.
    $tiles = $preset['tiles'];
    $spawn = $preset['spawn'];

    // Every tile you can stand on, not just floor. The map's links across the harbour are laid
    // as boards, so a flood that only followed '.' would stop at every bridge — which is exactly
    // what it did, and it failed this test rather than the map.
    $seen = [];
    $stack = [[$spawn['x'], $spawn['y']]];
    while ($stack) {
        [$x, $y] = array_pop($stack);
        $key = "$x,$y";
        if (isset($seen[$key]) || ! Tiles::isWalkable($tiles[$y][$x] ?? '#')) {
            continue;
        }
        $seen[$key] = true;
        $stack[] = [$x + 1, $y];
        $stack[] = [$x - 1, $y];
        $stack[] = [$x, $y + 1];
        $stack[] = [$x, $y - 1];
    }

    $walkable = 0;
    foreach ($tiles as $row) {
        foreach (str_split($row) as $tile) {
            $walkable += Tiles::isWalkable($tile) ? 1 : 0;
        }
    }

    expect(count($seen))->toBeGreaterThan((int) ($walkable * 0.95));

    /*
     * And the property the whole feature turns on: you can get *in* from the outside. Wherever
     * this map is placed, its rim is what meets the map next door, so a solid rim is a city
     * nobody can walk into — which is precisely the bug that took four attempts to fix.
     */
    $rim = [];
    for ($x = 0; $x < $preset['width']; $x++) {
        $rim[] = [$x, 0];
        $rim[] = [$x, $preset['height'] - 1];
    }
    for ($y = 0; $y < $preset['height']; $y++) {
        $rim[] = [0, $y];
        $rim[] = [$preset['width'] - 1, $y];
    }

    foreach ($rim as [$x, $y]) {
        expect(Tiles::isWalkable($tiles[$y][$x]))->toBeTrue("The rim blocks at $x,$y, so nothing placed beside this map could be walked into.");
        expect(isset($seen["$x,$y"]))->toBeTrue("The rim at $x,$y is walkable but cut off from the city.");
    }
});

it('saves doorways, and refuses ones that lead nowhere real', function () {
    [$owner, $server, $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    $doorway = fn (array $to) => [[
        'id' => 'p1', 'name' => 'Doorway', 'x' => 2, 'y' => 2, 'w' => 2, 'h' => 1, 'to' => $to,
    ]];

    // Somewhere else on this map, which the payload's own grid says you can stand on.
    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload([
        'portals' => $doorway(['kind' => 'point', 'x' => 6, 'y' => 6]),
    ]))
        ->assertOk()
        ->assertJsonPath('data.portals.0.to.x', 6);

    // A doorway you cannot step into is one that would silently never fire.
    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload([
        'portals' => [[
            'id' => 'p1', 'name' => 'Sealed', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1,
            'to' => ['kind' => 'point', 'x' => 5, 'y' => 5],
        ]],
    ]))->assertStatus(422)->assertJsonValidationErrors('portals.0');

    // An exit inside a wall would drop somebody into the masonry.
    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload([
        'portals' => $doorway(['kind' => 'point', 'x' => 0, 'y' => 0]),
    ]))->assertStatus(422)->assertJsonValidationErrors('portals.0.to');

    // A doorway is walked into by everybody in the room, so one pointing at a text channel is
    // not a broken link — it is a door to somewhere that isn't a place.
    $text = Channel::factory()->for($server)->create(['type' => 'text']);

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload([
        'portals' => $doorway(['kind' => 'room', 'channel_id' => $text->id]),
    ]))->assertStatus(422)->assertJsonValidationErrors('portals.0.to');
});

it('round-trips every part of a map the editor can change', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    /*
     * The guard for the bug this feature kept hitting.
     *
     * Three times running, a new part of the map — artwork, then its rename, then doorways —
     * was added to the editor, filled in correctly, and dropped on the way to the server by a
     * hand-maintained list of fields to send. The server read the missing field as "there are
     * none of those" and wiped it on every save. Nothing errored; the feature just didn't stick.
     *
     * So this saves a map with *one of everything* and demands it all comes back. It cannot see
     * the client's payload, but it pins the contract the client has to meet, and it fails loudly
     * the moment a field stops surviving a save.
     */
    $payload = validMapPayload([
        'projection' => 'iso',
        'backdrops' => [['key' => 'gather-town', 'x' => 1, 'y' => 1, 'w' => 5, 'h' => 4]],
        'portals' => [[
            'id' => 'p1', 'name' => 'To the park', 'x' => 2, 'y' => 2, 'w' => 2, 'h' => 1,
            'to' => ['kind' => 'point', 'x' => 6, 'y' => 6],
        ]],
        'zones' => [['id' => 'z1', 'name' => 'Corner', 'kind' => 'private', 'x' => 1, 'y' => 1, 'w' => 3, 'h' => 3]],
        'objects' => [['id' => 'o1', 'kind' => 'plant', 'x' => 4, 'y' => 4]],
    ]);

    $this->putJson("/api/channels/{$channel->id}/space/map", $payload)->assertOk();

    // Read back fresh, not from the save's own response: a field can be echoed by the resource
    // and still never have been written.
    $this->getJson("/api/channels/{$channel->id}/space/map")
        ->assertOk()
        ->assertJsonPath('data.name', 'Rebuilt')
        ->assertJsonPath('data.projection', 'iso')
        ->assertJsonPath('data.spawn.x', 5)
        ->assertJsonPath('data.zones.0.name', 'Corner')
        ->assertJsonPath('data.objects.0.kind', 'plant')
        ->assertJsonPath('data.backdrops.0.key', 'gather-town')
        ->assertJsonPath('data.backdrops.0.w', 5)
        ->assertJsonPath('data.portals.0.name', 'To the park')
        ->assertJsonPath('data.portals.0.to.kind', 'point')
        ->assertJsonPath('data.portals.0.to.y', 6);
});

it('accepts a map at the full size ceiling, and refuses one past it', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    /*
     * The ceiling is the one constant here whose cost grows with its *square*, so it gets an
     * actual round trip rather than a reading of the constant. 256 a side is 65,536 tiles — about
     * 64KB of grid in one document — and the point of this test is that a map that big still
     * validates, saves and comes back whole rather than tripping some limit further down.
     */
    $max = SideSpaceMap::MAX_SIZE;
    $rows = array_map(
        fn (int $y) => $y === 0 || $y === $max - 1 ? str_repeat('#', $max) : '#'.str_repeat('.', $max - 2).'#',
        range(0, $max - 1),
    );

    $this->putJson("/api/channels/{$channel->id}/space/map", [
        'name' => 'Vast',
        'width' => $max,
        'height' => $max,
        'tiles' => $rows,
        'zones' => [],
        'spawn' => ['x' => 5, 'y' => 5],
    ])->assertOk()->assertJsonPath('data.width', $max);

    expect($channel->spaceMap()->sole()->tiles)->toHaveCount($max);

    // And one tile past it is refused, so the bound is real rather than advisory.
    $over = $max + 1;
    $this->putJson("/api/channels/{$channel->id}/space/map", [
        'name' => 'Too big',
        'width' => $over,
        'height' => $over,
        'tiles' => array_fill(0, $over, str_repeat('.', $over)),
        'zones' => [],
        'spawn' => ['x' => 5, 'y' => 5],
    ])->assertStatus(422)->assertJsonValidationErrors(['width', 'height']);
});

it('refuses a doorway into another server', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    // A Side Space somewhere else entirely — the one destination that must never be reachable,
    // because who may follow somebody through it is a question this feature does not answer.
    [, , $elsewhere] = ownerWithSpaceChannel();

    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload([
        'portals' => [[
            'id' => 'p1', 'name' => 'Out', 'x' => 2, 'y' => 2, 'w' => 1, 'h' => 1,
            'to' => ['kind' => 'room', 'channel_id' => $elsewhere->id],
        ]],
    ]))->assertStatus(422)->assertJsonValidationErrors('portals.0.to');
});

it('refuses a projection nothing knows how to draw', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload(['projection' => 'dimetric']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('projection');
});

it('lets a plain member rebuild the room too', function () {
    Event::fake([SideSpaceMapUpdated::class]);

    [, $server, $channel] = ownerWithSpaceChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    Passport::actingAs($member);

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload())->assertOk();

    // Rebuilt, and credited: a shared room records who last laid its floor.
    expect($channel->spaceMap()->sole()->width)->toBe(10)
        ->and($channel->spaceMap()->sole()->updated_by)->toBe($member->id);

    Event::assertDispatched(SideSpaceMapUpdated::class);
});

it('still refuses a stranger to the server', function () {
    [, , $channel] = ownerWithSpaceChannel();

    Passport::actingAs(User::factory()->create());

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload())->assertForbidden();

    expect($channel->spaceMap()->sole()->width)->toBe(30);
});

it('lets any member rearrange the furniture, but not the geometry', function () {
    Event::fake([SideSpaceMapUpdated::class]);

    [, $server, $channel] = ownerWithSpaceChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);
    Passport::actingAs($member);

    // The office preset stands people on floorboards; a member may put a plant on one.
    $this->putJson("/api/channels/{$channel->id}/space/objects", [
        'objects' => [['id' => 'mine-1', 'kind' => 'plant', 'x' => 14, 'y' => 10]],
    ])->assertOk()->assertJsonCount(1, 'data.objects');

    $map = $channel->spaceMap()->sole();

    expect($map->objects)->toHaveCount(1)
        // The geometry the member never sent is exactly as it was — width, and the editor credit.
        ->and($map->width)->toBe(30)
        ->and($map->updated_by)->toBeNull();

    Event::assertDispatched(SideSpaceMapUpdated::class);
});

it('holds a member decorating to the same furniture rules as the owner', function () {
    [, $server, $channel] = ownerWithSpaceChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);
    Passport::actingAs($member);

    // A painting has to hang on a wall; the middle of the floor is not one.
    $this->putJson("/api/channels/{$channel->id}/space/objects", [
        'objects' => [['id' => 'x', 'kind' => 'painting', 'x' => 14, 'y' => 10]],
    ])->assertStatus(422)->assertJsonValidationErrors('objects.0');
});

it('forbids a non-member from decorating', function () {
    [, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs(User::factory()->create());

    $this->putJson("/api/channels/{$channel->id}/space/objects", ['objects' => []])->assertForbidden();
});

it('rejects a grid that is not the size it claims', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    // Rows of 9 characters in a map that says it is 10 wide.
    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload([
        'tiles' => array_fill(0, 10, str_repeat('#', 9)),
    ]))->assertStatus(422)->assertJsonValidationErrors('tiles.0');

    // …and one row short of the height it claims.
    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload([
        'tiles' => array_slice(validMapPayload()['tiles'], 0, 9),
    ]))->assertStatus(422)->assertJsonValidationErrors('tiles');
});

it('rejects tiles it does not know how to draw', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    $tiles = validMapPayload()['tiles'];
    $tiles[5] = '#..XXXX..#';

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload(['tiles' => $tiles]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('tiles.5');
});

it('rejects an entrance inside a wall', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload(['spawn' => ['x' => 0, 'y' => 0]]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('spawn');
});

it('rejects a zone that runs off the map or has nowhere to stand', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    $offMap = ['id' => 'a', 'name' => 'Off', 'kind' => 'private', 'x' => 8, 'y' => 8, 'w' => 6, 'h' => 6];
    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload(['zones' => [$offMap]]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('zones.0');

    // Entirely on the wall border: legal geometry, but a room nobody can be inside.
    $solid = ['id' => 'a', 'name' => 'Solid', 'kind' => 'private', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1];
    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload(['zones' => [$solid]]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('zones.0');
});

it('saves a stage zone, and refuses a kind of zone it has never heard of', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    // A stage is stored exactly as a sealed room is — a rectangle with a kind. Who is live on
    // it at any moment is worked out in the browser from whispered positions and never reaches
    // the server, so there is nothing else here to persist.
    $stage = ['id' => 's', 'name' => 'Main stage', 'kind' => 'stage', 'x' => 2, 'y' => 2, 'w' => 4, 'h' => 3];

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload(['zones' => [$stage]]))
        ->assertOk()
        ->assertJsonPath('data.zones.0.kind', 'stage');

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload([
        'zones' => [[...$stage, 'kind' => 'auditorium']],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('zones.0.kind');
});

it('rejects a grid bigger than the ceiling', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    $over = SideSpaceMap::MAX_SIZE + 1;

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload([
        'width' => $over,
        'height' => $over,
        'tiles' => array_fill(0, $over, str_repeat('.', $over)),
    ]))->assertStatus(422)->assertJsonValidationErrors(['width', 'height']);
});

// --- remembering where people stood ---

it('remembers where a member was standing, without broadcasting it', function () {
    Event::fake([SideSpaceMapUpdated::class, VoiceStateUpdated::class]);

    [$owner, , $channel] = ownerWithSpaceChannel();
    VoiceParticipant::factory()->create([
        'channel_id' => $channel->id, 'user_id' => $owner->id, 'last_seen_at' => now(),
    ]);

    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/space/position", ['x' => 7, 'y' => 3, 'facing' => 'left'])
        ->assertNoContent();

    $row = VoiceParticipant::where('channel_id', $channel->id)->where('user_id', $owner->id)->sole();
    expect($row->x)->toBe(7)->and($row->y)->toBe(3)->and($row->facing)->toBe('left');

    // Nobody is told. Everyone who needs to know is already getting it over whispers — and
    // in particular this must not fan VoiceStateUpdated at the whole server every few seconds.
    Event::assertNotDispatched(VoiceStateUpdated::class);
    Event::assertNotDispatched(SideSpaceMapUpdated::class);
});

it('accepts a position from someone who has already left, and changes nothing', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    // No participant row: they walked out between the throttle firing and this landing.
    $this->postJson("/api/channels/{$channel->id}/space/position", ['x' => 7, 'y' => 3])
        ->assertNoContent();

    expect(VoiceParticipant::where('channel_id', $channel->id)->count())->toBe(0);
});

it('forbids a non-member from writing a position', function () {
    [, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs(User::factory()->create());

    $this->postJson("/api/channels/{$channel->id}/space/position", ['x' => 1, 'y' => 1])->assertForbidden();
});

it('exposes a remembered position on the voice roster', function () {
    [$owner, $server, $channel] = ownerWithSpaceChannel();
    VoiceParticipant::factory()->create([
        'channel_id' => $channel->id, 'user_id' => $owner->id, 'last_seen_at' => now(), 'x' => 4, 'y' => 9,
    ]);

    Passport::actingAs($owner);

    // A Side Space appears in the sidebar roster exactly as a voice channel does.
    $this->getJson("/api/servers/{$server->id}/voice")
        ->assertOk()
        ->assertJsonPath("data.{$channel->id}.0.x", 4)
        ->assertJsonPath("data.{$channel->id}.0.y", 9);
});

// --- the call ---

it('lets a member join the call in a Side Space', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/voice/join")->assertOk();

    expect(VoiceParticipant::where('channel_id', $channel->id)->count())->toBe(1);
});

it('holds far more people than a voice channel', function () {
    [, , $space] = ownerWithSpaceChannel();
    [, , $voice] = ownerWithVoiceChannel();
    $voiceService = app(VoiceService::class);

    expect($voiceService->capacity($space))->toBeGreaterThan($voiceService->capacity($voice));
});

// --- the model's own rules ---

it('walks back to a legal tile when the entrance has been painted over', function () {
    [, , $channel] = ownerWithSpaceChannel();
    $map = $channel->spaceMap;

    // The stored spawn is now a wall — a map saved before the rule existed, or a grid resized
    // out from under it.
    $map->update(['spawn' => ['x' => 0, 'y' => 0]]);

    $spawn = $map->fresh()->spawnPoint();

    expect($map->fresh()->isWalkable($spawn['x'], $spawn['y']))->toBeTrue();
});

it('treats everything off the edge of the map as solid', function () {
    [, , $channel] = ownerWithSpaceChannel();
    $map = $channel->spaceMap;

    expect($map->isWalkable(-1, 5))->toBeFalse()
        ->and($map->isWalkable(5, -1))->toBeFalse()
        ->and($map->isWalkable(999, 999))->toBeFalse();
});

it('finds the zone a tile is in, and none out in the open', function () {
    [, , $channel] = ownerWithSpaceChannel();
    $map = $channel->spaceMap;

    // Inside meeting room A (x 6..11, y 4..6 in the office preset).
    expect($map->zoneAt(7, 5)['id'])->toBe('meet-a')
        ->and($map->zoneAt(15, 10))->toBeNull();
});

// --- furniture ---

it('builds every preset as a room the API would accept', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    // The one map nobody can fix from the editor is the one everybody's room starts as, so
    // each preset is pushed through the very validator a hand-built save goes through. A
    // preset with a row one character short, or a bench standing in a pond, fails here rather
    // than on somebody's first channel.
    foreach (MapPresets::all() as $key => $preset) {
        $this->putJson("/api/channels/{$channel->id}/space/map", [
            'name' => $preset['name'],
            'width' => $preset['width'],
            'height' => $preset['height'],
            'tiles' => $preset['tiles'],
            'zones' => $preset['zones'],
            'objects' => $preset['objects'],
            'spawn' => $preset['spawn'],
        ])->assertOk("The '$key' preset is not a legal room.");
    }
});

it('stores furniture and hands it back with the map', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload([
        'objects' => [
            ['id' => 'd-1', 'kind' => 'speaker', 'x' => 3, 'y' => 3],
            ['id' => 'd-2', 'kind' => 'painting', 'x' => 4, 'y' => 0],
        ],
    ]))->assertOk()->assertJsonCount(2, 'data.objects');

    $this->getJson("/api/channels/{$channel->id}/space/map")
        ->assertOk()
        ->assertJsonPath('data.objects.0.kind', 'speaker');
});

it('makes solid furniture as impassable as a wall', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload([
        // A desk is two tiles wide, and neither of them is somewhere you can stand.
        'objects' => [['id' => 'd-1', 'kind' => 'desk', 'x' => 3, 'y' => 3]],
    ]))->assertOk();

    $map = $channel->spaceMap()->sole();

    expect($map->isWalkable(3, 3))->toBeFalse()
        ->and($map->isWalkable(4, 3))->toBeFalse()
        // …and the tile beyond the far end of it still is.
        ->and($map->isWalkable(5, 3))->toBeTrue();
});

it('lets flat furniture be walked on, and stood on top of', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    // A rug with a couch on it: the overlap is the point, not a mistake.
    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload([
        'objects' => [
            ['id' => 'd-1', 'kind' => 'rug', 'x' => 3, 'y' => 3],
            ['id' => 'd-2', 'kind' => 'couch', 'x' => 3, 'y' => 3],
        ],
    ]))->assertOk();

    $map = $channel->spaceMap()->sole();

    // The couch blocks its own two tiles; the rest of the rug is still floor.
    expect($map->isWalkable(3, 3))->toBeFalse()
        ->and($map->isWalkable(3, 4))->toBeTrue();
});

it('refuses furniture that is off the map, misplaced, or doubled up', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    $put = fn (array $objects) => $this->putJson(
        "/api/channels/{$channel->id}/space/map",
        validMapPayload(['objects' => $objects]),
    );

    // A kind nobody has artwork for.
    $put([['id' => 'd-1', 'kind' => 'hovercraft', 'x' => 3, 'y' => 3]])
        ->assertStatus(422)->assertJsonValidationErrors('objects.0.kind');

    // A two-wide desk with only one tile of map left to stand on.
    $put([['id' => 'd-1', 'kind' => 'desk', 'x' => 9, 'y' => 3]])
        ->assertStatus(422)->assertJsonValidationErrors('objects.0');

    // A painting in mid-air, and a plant inside the wall: each is the other's mistake.
    $put([['id' => 'd-1', 'kind' => 'painting', 'x' => 5, 'y' => 5]])
        ->assertStatus(422)->assertJsonValidationErrors('objects.0');
    $put([['id' => 'd-1', 'kind' => 'plant', 'x' => 0, 'y' => 0]])
        ->assertStatus(422)->assertJsonValidationErrors('objects.0');

    // Two solid things in the same square.
    $put([
        ['id' => 'd-1', 'kind' => 'plant', 'x' => 3, 'y' => 3],
        ['id' => 'd-2', 'kind' => 'crate', 'x' => 3, 'y' => 3],
    ])->assertStatus(422)->assertJsonValidationErrors('objects.1');
});

// --- which way things are turned ---

it('measures a turned piece by the footprint it actually takes up', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    $put = fn (array $objects) => $this->putJson(
        "/api/channels/{$channel->id}/space/map",
        validMapPayload(['objects' => $objects]),
    );

    // The room is 10×10 with a wall all round, so the floor is x1–8 by y1–8. A two-wide desk at
    // x8 runs into the east wall; the same desk turned a quarter is one wide and two deep, and
    // fits there perfectly. Same piece, same square, opposite answers — which is the whole point.
    $put([['id' => 'd-1', 'kind' => 'desk', 'x' => 8, 'y' => 3]])
        ->assertStatus(422)->assertJsonValidationErrors('objects.0');

    $put([['id' => 'd-1', 'kind' => 'desk', 'x' => 8, 'y' => 3, 'facing' => 'left']])
        ->assertOk()
        ->assertJsonPath('data.objects.0.facing', 'left');

    // …and the turned footprint is checked in the direction it now points: two deep at y8 runs
    // into the south wall.
    $put([['id' => 'd-1', 'kind' => 'desk', 'x' => 3, 'y' => 8, 'facing' => 'left']])
        ->assertStatus(422)->assertJsonValidationErrors('objects.0');

    // Collision follows the turn too: the desk now occupies the tile *below* its origin.
    $put([
        ['id' => 'd-1', 'kind' => 'desk', 'x' => 3, 'y' => 3, 'facing' => 'right'],
        ['id' => 'd-2', 'kind' => 'plant', 'x' => 3, 'y' => 4],
    ])->assertStatus(422)->assertJsonValidationErrors('objects.1');

    // Unturned, that same pair is fine — the desk runs east instead.
    $put([
        ['id' => 'd-1', 'kind' => 'desk', 'x' => 3, 'y' => 3],
        ['id' => 'd-2', 'kind' => 'plant', 'x' => 3, 'y' => 4],
    ])->assertOk();
});

it('blocks the tiles a turned piece stands on, and no others', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload([
        'objects' => [['id' => 'd-1', 'kind' => 'desk', 'x' => 3, 'y' => 3, 'facing' => 'left']],
    ]))->assertOk();

    $map = $channel->spaceMap()->sole();

    expect($map->isWalkable(3, 3))->toBeFalse()
        ->and($map->isWalkable(3, 4))->toBeFalse()  // the tile the turn moved it onto
        ->and($map->isWalkable(4, 3))->toBeTrue();  // the one it used to be on, now free
});

it('refuses a way of facing that is not one of the four', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload([
        'objects' => [['id' => 'd-1', 'kind' => 'plant', 'x' => 3, 'y' => 3, 'facing' => 'sideways']],
    ]))->assertStatus(422)->assertJsonValidationErrors('objects.0.facing');
});

it('lets a member turn the furniture as well as move it', function () {
    [, $server, $channel] = ownerWithSpaceChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);
    Passport::actingAs($member);

    $this->putJson("/api/channels/{$channel->id}/space/objects", [
        'objects' => [['id' => 'mine-1', 'kind' => 'couch', 'x' => 3, 'y' => 3, 'facing' => 'right']],
    ])->assertOk()->assertJsonPath('data.objects.0.facing', 'right');

    expect($channel->spaceMap()->sole()->objects[0]['facing'])->toBe('right');
});

it('refuses an entrance under the furniture', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload([
        'objects' => [['id' => 'd-1', 'kind' => 'bookshelf', 'x' => 5, 'y' => 5]],
        'spawn' => ['x' => 5, 'y' => 5],
    ]))->assertStatus(422)->assertJsonValidationErrors('spawn');
});

// --- using the furniture ---

it('opens the channel widget a piece of furniture points at', function () {
    [$owner, $server, $channel] = ownerWithSpaceChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload([
        'objects' => [
            ['id' => 'speaker-1', 'kind' => 'speaker', 'x' => 3, 'y' => 3],
            ['id' => 'tv-1', 'kind' => 'tv', 'x' => 5, 'y' => 7],
            ['id' => 'plant-1', 'kind' => 'plant', 'x' => 7, 'y' => 7],
        ],
    ]))->assertOk();

    // Any member may use it — pressing E on the room's speaker is no more privileged than
    // typing `m!` in the channel, and lands on the very same widget.
    Passport::actingAs($member);

    // The answer says which kind of door this was, then hands over the widget behind it.
    $this->postJson("/api/channels/{$channel->id}/space/interact", ['object_id' => 'speaker-1'])
        ->assertOk()
        ->assertJsonPath('type', 'widget')
        ->assertJsonPath('app', 'music')
        ->assertJsonPath('data.type', 'music')
        ->assertJsonPath('data.channel_id', $channel->id);

    $this->postJson("/api/channels/{$channel->id}/space/interact", ['object_id' => 'tv-1'])
        ->assertOk()
        ->assertJsonPath('data.type', 'video');

    // One widget per channel and type: the second person at the speaker joins the first
    // person's session rather than starting their own.
    Passport::actingAs($owner);
    $this->postJson("/api/channels/{$channel->id}/space/interact", ['object_id' => 'speaker-1'])->assertOk();

    expect(Widget::where('channel_id', $channel->id)->where('type', 'music')->count())->toBe(1);
});

it('opens a Side Desk app for furniture that points at one, without inventing a widget', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload([
        'objects' => [
            ['id' => 'board-1', 'kind' => 'whiteboard', 'x' => 3, 'y' => 3],
            ['id' => 'lectern-1', 'kind' => 'lectern', 'x' => 6, 'y' => 3],
        ],
    ]))->assertOk();

    // The whiteboard in the room is the channel's Board tab, so the answer is the app's name and
    // nothing else — there is no widget row behind a surface app to create or return.
    $this->postJson("/api/channels/{$channel->id}/space/interact", ['object_id' => 'board-1'])
        ->assertOk()
        ->assertJsonPath('type', 'app')
        ->assertJsonPath('app', 'board')
        ->assertJsonMissingPath('data');

    $this->postJson("/api/channels/{$channel->id}/space/interact", ['object_id' => 'lectern-1'])
        ->assertOk()
        ->assertJsonPath('app', 'notes');

    expect(Widget::where('channel_id', $channel->id)->count())->toBe(0);
});

it('404s on furniture that does nothing, or that is not there', function () {
    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload([
        'objects' => [['id' => 'plant-1', 'kind' => 'plant', 'x' => 3, 'y' => 3]],
    ]))->assertOk();

    $this->postJson("/api/channels/{$channel->id}/space/interact", ['object_id' => 'plant-1'])->assertNotFound();
    $this->postJson("/api/channels/{$channel->id}/space/interact", ['object_id' => 'ghost'])->assertNotFound();
});

it('forbids a non-member from using the furniture', function () {
    [, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs(User::factory()->create());

    $this->postJson("/api/channels/{$channel->id}/space/interact", ['object_id' => 'd-0'])->assertForbidden();
});

// --- how you look ---

it('remembers what you look like and who is following you', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->patchJson('/api/space/appearance', [
        'avatar' => [
            'body' => 'feminine',
            'hair' => 'ponytail',
            'hair_color' => 'auburn',
            'skin' => 'deep',
            'outfit' => 'violet',
        ],
        'pet' => 'emberpup',
    ])->assertOk()
        ->assertJsonPath('data.space_avatar.hair', 'ponytail')
        ->assertJsonPath('data.space_pet', 'emberpup');

    expect($user->fresh()->space_avatar['skin'])->toBe('deep');

    // Sending the pet home is an explicit null, not an omission.
    $this->patchJson('/api/space/appearance', ['pet' => null])
        ->assertOk()
        ->assertJsonPath('data.space_pet', null)
        ->assertJsonPath('data.space_avatar.hair', 'ponytail');
});

it('keeps a shout up until it is turned off', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    // Whitespace is collapsed: a bubble is one line whatever was pasted into it.
    $this->patchJson('/api/space/appearance', ['shout' => "  Worship 🙌\n\n"])
        ->assertOk()
        ->assertJsonPath('data.space_shout', 'Worship 🙌');

    // Saving something else entirely leaves it alone — the key is what changes it.
    $this->patchJson('/api/space/appearance', ['pet' => 'emberpup'])
        ->assertOk()
        ->assertJsonPath('data.space_shout', 'Worship 🙌');

    // Turning it off, both ways round: an explicit null, and a box that was emptied.
    $this->patchJson('/api/space/appearance', ['shout' => '   '])
        ->assertOk()
        ->assertJsonPath('data.space_shout', null);

    $this->patchJson('/api/space/appearance', ['shout' => 'Back'])->assertOk();
    $this->patchJson('/api/space/appearance', ['shout' => null])
        ->assertOk()
        ->assertJsonPath('data.space_shout', null);

    expect($user->fresh()->space_shout)->toBeNull();
});

it('refuses a shout too long to fit in the bubble', function () {
    Passport::actingAs(User::factory()->create());

    $this->patchJson('/api/space/appearance', ['shout' => str_repeat('a', 41)])
        ->assertStatus(422)->assertJsonValidationErrors('shout');
});

it('refuses a look nobody has artwork for', function () {
    Passport::actingAs(User::factory()->create());

    $this->patchJson('/api/space/appearance', [
        'avatar' => ['body' => 'slim', 'hair' => 'mohawk', 'hair_color' => 'brown', 'skin' => 'fair', 'outfit' => 'auto'],
    ])->assertStatus(422)->assertJsonValidationErrors('avatar.hair');

    $this->patchJson('/api/space/appearance', ['pet' => 'dragonite'])
        ->assertStatus(422)->assertJsonValidationErrors('pet');

    $this->patchJson('/api/space/appearance', [
        'avatar' => ['body' => 'slim', 'hair' => 'short', 'hair_color' => 'brown', 'skin' => 'fair', 'outfit' => 'auto', 'costume' => 'batman'],
    ])->assertStatus(422)->assertJsonValidationErrors('avatar.costume');
});

it('puts a costume on without losing the person underneath', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->patchJson('/api/space/appearance', [
        'avatar' => ['body' => 'sturdy', 'hair' => 'curly', 'hair_color' => 'ash', 'skin' => 'brown', 'outfit' => 'teal', 'costume' => 'cantor'],
    ])->assertOk()
        ->assertJsonPath('data.space_avatar.costume', 'cantor')
        // Still stored, and still theirs — a costume is worn over a look, not instead of one.
        ->assertJsonPath('data.space_avatar.hair', 'curly')
        ->assertJsonPath('data.space_avatar.skin', 'brown');

    // Taking it off is a value, not an omission, and puts the same person back.
    $this->patchJson('/api/space/appearance', [
        'avatar' => ['body' => 'sturdy', 'hair' => 'curly', 'hair_color' => 'ash', 'skin' => 'brown', 'outfit' => 'teal', 'costume' => 'none'],
    ])->assertOk()->assertJsonPath('data.space_avatar.costume', 'none');

    expect($user->fresh()->space_avatar['hair'])->toBe('curly');
});

it('reads a look saved before costumes existed as wearing none', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    // A row written by the client that shipped before this feature: five keys, no sixth.
    $this->patchJson('/api/space/appearance', [
        'avatar' => ['body' => 'slim', 'hair' => 'bob', 'hair_color' => 'blonde', 'skin' => 'fair', 'outfit' => 'auto'],
    ])->assertOk()->assertJsonPath('data.space_avatar.costume', 'none');
});

it('serves a complete look for somebody who has never chosen one', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    // Never null: every client draws a sprite for everybody, so "hasn't chosen" has to arrive
    // as something drawable rather than as an absence each of them handles differently.
    $this->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('data.space_avatar.body', 'slim')
        ->assertJsonPath('data.space_pet', null);
});

// --- summoning the room ---

/*
 * "Everyone follow me" is the one thing in a Side Space that moves somebody else's avatar
 * without asking them, so what's worth testing is not that it works but that it is *shut*: a
 * member who could call this could drag the room around, which is the whole reason it's an
 * endpoint rather than one more peer-to-peer whisper alongside the movement ones.
 */

it('lets staff summon the room', function () {
    Event::fake([SideSpaceSummoned::class]);

    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/space/summon", [
        'user_ids' => null,
        'following' => true,
    ])->assertNoContent();

    Event::assertDispatched(SideSpaceSummoned::class, function (SideSpaceSummoned $event) use ($channel, $owner) {
        // A null list is the room, and it travels as null rather than as a roster: every client
        // already knows who is standing in there.
        return $event->channelId === $channel->id
            && $event->leader->is($owner)
            && $event->userIds === null
            && $event->following === true;
    });
});

it('refuses a plain member the room', function () {
    Event::fake([SideSpaceSummoned::class]);

    [, $server, $channel] = ownerWithSpaceChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    Passport::actingAs($member);

    $this->postJson("/api/channels/{$channel->id}/space/summon", [
        'user_ids' => null,
        'following' => true,
    ])->assertForbidden();

    Event::assertNotDispatched(SideSpaceSummoned::class);
});

it('refuses a stranger to the server', function () {
    Event::fake([SideSpaceSummoned::class]);

    [, , $channel] = ownerWithSpaceChannel();

    Passport::actingAs(User::factory()->create());

    $this->postJson("/api/channels/{$channel->id}/space/summon", [
        'user_ids' => null,
        'following' => true,
    ])->assertForbidden();

    Event::assertNotDispatched(SideSpaceSummoned::class);
});

it('carries the named few when only some are summoned', function () {
    Event::fake([SideSpaceSummoned::class]);

    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/space/summon", [
        'user_ids' => [7, 9],
        'following' => true,
    ])->assertNoContent();

    Event::assertDispatched(SideSpaceSummoned::class, fn (SideSpaceSummoned $event) => $event->userIds === [7, 9]);
});

it('releases with the same call, the other way round', function () {
    Event::fake([SideSpaceSummoned::class]);

    [$owner, , $channel] = ownerWithSpaceChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/space/summon", [
        'user_ids' => null,
        'following' => false,
    ])->assertNoContent();

    Event::assertDispatched(SideSpaceSummoned::class, fn (SideSpaceSummoned $event) => $event->following === false);
});

it('is not a thing you can do to a channel nobody walks around in', function () {
    Event::fake([SideSpaceSummoned::class]);

    [$owner, $server] = ownerWithServer();
    $text = Channel::factory()->for($server)->create(['type' => 'text']);

    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$text->id}/space/summon", [
        'user_ids' => null,
        'following' => true,
    ])->assertNotFound();

    Event::assertNotDispatched(SideSpaceSummoned::class);
});
