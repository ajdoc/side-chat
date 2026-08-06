<?php

use App\Events\SideSpaceMapUpdated;
use App\Events\VoiceStateUpdated;
use App\Models\Channel;
use App\Models\SideSpaceMap;
use App\Models\User;
use App\Models\VoiceParticipant;
use App\Models\Widget;
use App\Services\VoiceService;
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

    $channel = Channel::where('name', 'the-office')->sole();
    $map = $channel->spaceMap;

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
