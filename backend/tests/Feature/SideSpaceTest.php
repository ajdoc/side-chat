<?php

use App\Events\SideSpaceMapUpdated;
use App\Events\VoiceStateUpdated;
use App\Models\Channel;
use App\Models\SideSpaceMap;
use App\Models\User;
use App\Models\VoiceParticipant;
use App\Support\SideSpace\MapPresets;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;

/*
 * A Side Space is a channel you walk around in. Almost everything about it — messages, threads,
 * the call — is the existing stack unchanged, so what's worth testing here is the part that is
 * genuinely new: that a room gets built when the channel is, that only its owner can rebuild it,
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

it('lists the map presets', function () {
    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/space/map-presets')
        ->assertOk()
        ->assertJsonCount(count(MapPresets::keys()), 'data')
        ->assertJsonPath('data.0.key', 'office');
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

it('forbids a plain member from rebuilding the room', function () {
    [, $server, $channel] = ownerWithSpaceChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    Passport::actingAs($member);

    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload())->assertForbidden();

    // Untouched — the room they're standing in is still the one they were standing in.
    expect($channel->spaceMap()->sole()->width)->toBe(30);
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
    $voiceService = app(App\Services\VoiceService::class);

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
