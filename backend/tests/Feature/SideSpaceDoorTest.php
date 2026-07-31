<?php

use App\Models\Channel;
use App\Models\SideSpaceLock;
use App\Models\SideSpaceRoom;
use App\Models\User;
use App\Support\SideSpace\Doors;
use Laravel\Passport\Passport;

/**
 * Doors, room owners and locks.
 *
 * The thing under test is not really the endpoints — it's the answer to "who may shut whom out",
 * and the reason it has a file of its own is that this is the first part of a Side Space where
 * getting the answer wrong locks real people out of a real room.
 *
 * One property matters more than the rest and is asserted twice over: **a lock is not part of
 * the map**. Any member may save the map, so if saving one could disturb a lock, every other
 * rule here would be decoration.
 */

/**
 * A small room with a door in its wall.
 *
 *   - a 12×12 box, floor inside
 *   - a zone `study` covering the top-left quarter
 *   - a door in the gap in that room's wall
 *
 * @return array{0: User, 1: \App\Models\Server, 2: Channel, 3: \App\Models\SideSpaceMap}
 */
function spaceWithDoor(): array
{
    [$owner, $server] = ownerWithServer();
    $channel = Channel::factory()->create(['server_id' => $server->id, 'type' => 'space']);

    $tiles = [];
    for ($y = 0; $y < 12; $y++) {
        $tiles[] = $y === 0 || $y === 11 ? str_repeat('#', 12) : '#'.str_repeat('.', 10).'#';
    }

    // The study's wall, with one gap at x=3 for the doorway.
    $tiles[5] = '#'.str_repeat('#', 2).'.'.str_repeat('#', 2).str_repeat('.', 6).'#';

    $map = $channel->spaceMap()->create([
        'name' => 'Doors',
        'width' => 12,
        'height' => 12,
        'tiles' => $tiles,
        'zones' => [['id' => 'study', 'name' => 'The study', 'kind' => 'private', 'x' => 1, 'y' => 1, 'w' => 4, 'h' => 4]],
        // Sitting in the gap, so it opens onto the study without being inside it — the ordinary
        // shape of a doorway, and the case Doors::zoneFor exists for.
        'objects' => [['id' => 'door-1', 'kind' => 'door', 'x' => 3, 'y' => 5, 'facing' => 'down']],
        'spawn' => ['x' => 8, 'y' => 8],
    ]);

    return [$owner, $server, $channel, $map];
}

/** Somebody in the server who owns nothing. */
function spaceMember(\App\Models\Server $server, string $name = 'Member'): User
{
    $user = User::factory()->create(['name' => $name]);
    $server->members()->attach($user->id, ['role' => 'member']);

    return $user;
}

// --- room ownership ---

it('puts several people in charge of one room', function () {
    [$owner, $server, $channel] = spaceWithDoor();
    $alice = spaceMember($server, 'Alice');
    $bob = spaceMember($server, 'Bob');

    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/space/rooms/study", ['owner_ids' => [$alice->id, $bob->id]])
        ->assertOk();

    expect(SideSpaceRoom::where('zone_id', 'study')->pluck('owner_id')->all())
        ->toEqualCanonicalizing([$alice->id, $bob->id]);

    // Both may lock its doors, and both hold a key without being given one.
    Passport::actingAs($bob);
    $response = $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", ['allowed' => []])->assertOk();

    expect($response->json('data.locks.0.allowed'))->toEqualCanonicalizing([$alice->id, $bob->id]);
});

it('replaces the whole set of owners rather than adding to it', function () {
    [$owner, $server, $channel] = spaceWithDoor();
    $alice = spaceMember($server, 'Alice');
    $bob = spaceMember($server, 'Bob');

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/rooms/study", ['owner_ids' => [$alice->id, $bob->id]])->assertOk();
    // Dropping Alice is the same call as adding her was.
    $this->putJson("/api/channels/{$channel->id}/space/rooms/study", ['owner_ids' => [$bob->id]])->assertOk();

    expect(SideSpaceRoom::where('zone_id', 'study')->pluck('owner_id')->all())->toBe([$bob->id]);
});

it('refuses the whole list when one of the names is not a member', function () {
    [$owner, $server, $channel] = spaceWithDoor();
    $alice = spaceMember($server, 'Alice');

    Passport::actingAs($owner);

    // All of them or none: a half-applied list leaves the room in a state nobody asked for.
    $this->putJson("/api/channels/{$channel->id}/space/rooms/study", [
        'owner_ids' => [$alice->id, User::factory()->create()->id],
    ])->assertStatus(422);

    expect(SideSpaceRoom::count())->toBe(0);
});

it('lets the server owner put somebody in charge of a room', function () {
    [$owner, $server, $channel] = spaceWithDoor();
    $alice = spaceMember($server, 'Alice');

    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/space/rooms/study", ['owner_ids' => [$alice->id]])
        ->assertOk()
        ->assertJsonPath('data.rooms.0.zone_id', 'study')
        ->assertJsonPath('data.rooms.0.owner', 'Alice');

    expect(SideSpaceRoom::where('zone_id', 'study')->value('owner_id'))->toBe($alice->id);
});

it('refuses to put a plain member in charge of a room', function () {
    [, $server, $channel] = spaceWithDoor();
    $alice = spaceMember($server, 'Alice');
    $bob = spaceMember($server, 'Bob');

    // A member may rebuild the whole room, but not appoint anybody to it — appointing is the
    // root permission here, and one a member could grant themselves is not a permission.
    Passport::actingAs($bob);

    $this->putJson("/api/channels/{$channel->id}/space/rooms/study", ['owner_ids' => [$alice->id]])
        ->assertForbidden();

    expect(SideSpaceRoom::count())->toBe(0);
});

it('refuses an owner who is not in the server, and a room that is not on the map', function () {
    [$owner, , $channel] = spaceWithDoor();

    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/space/rooms/study", ['owner_ids' => [User::factory()->create()->id]])
        ->assertStatus(422);

    $this->putJson("/api/channels/{$channel->id}/space/rooms/nowhere", ['owner_ids' => [$owner->id]])
        ->assertNotFound();
});

it('takes a room back when the owner is cleared', function () {
    [$owner, $server, $channel] = spaceWithDoor();
    $alice = spaceMember($server, 'Alice');

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/rooms/study", ['owner_ids' => [$alice->id]])->assertOk();
    $this->putJson("/api/channels/{$channel->id}/space/rooms/study", ['owner_ids' => []])->assertOk();

    // The row goes, rather than lingering as an owner that is not a person.
    expect(SideSpaceRoom::count())->toBe(0);
});

// --- locking ---

it('lets the owner of a room lock its door, and says who may pass', function () {
    [$owner, $server, $channel] = spaceWithDoor();
    $alice = spaceMember($server, 'Alice');
    $bob = spaceMember($server, 'Bob');

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/rooms/study", ['owner_ids' => [$alice->id]])->assertOk();

    Passport::actingAs($alice);
    $response = $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", ['allowed' => [$bob->id]])
        ->assertOk();

    // The lock knows which room it guards, worked out from the doorway rather than sent.
    expect(SideSpaceLock::sole()->zone_id)->toBe('study');

    // Bob was given a key, and Alice never needed one — she owns the room and set the lock.
    // The server's owner is deliberately *not* here; see Doors::keyholders.
    expect($response->json('data.locks.0.allowed'))
        ->toEqualCanonicalizing([$bob->id, $alice->id]);
});

it('refuses to let a member lock a door in a room that is not theirs', function () {
    [$owner, $server, $channel] = spaceWithDoor();
    $alice = spaceMember($server, 'Alice');
    $bob = spaceMember($server, 'Bob');

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/rooms/study", ['owner_ids' => [$alice->id]])->assertOk();

    Passport::actingAs($bob);
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", ['allowed' => []])->assertForbidden();

    expect(SideSpaceLock::count())->toBe(0);
});

it('lets the server owner lock any door, including one nobody is responsible for', function () {
    [$owner, , $channel, $map] = spaceWithDoor();

    // A second door, standing in the open — no zone touches it.
    $map->update(['objects' => [
        ...$map->objects,
        ['id' => 'door-2', 'kind' => 'door', 'x' => 8, 'y' => 8, 'facing' => 'down'],
    ]]);

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-2", ['allowed' => []])->assertOk();

    // No room to belong to, which is precisely why only the server's owner could have done it.
    expect(SideSpaceLock::where('object_id', 'door-2')->value('zone_id'))->toBeNull();
});

it('404s on locking something that is not a door', function () {
    [$owner, , $channel, $map] = spaceWithDoor();
    $map->update(['objects' => [...$map->objects, ['id' => 'plant-1', 'kind' => 'plant', 'x' => 8, 'y' => 8]]]);

    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/space/locks/plant-1", ['allowed' => []])->assertNotFound();
    $this->putJson("/api/channels/{$channel->id}/space/locks/nothing", ['allowed' => []])->assertNotFound();
});

it('unlocks a door, and treats unlocking an open one as done', function () {
    [$owner, , $channel] = spaceWithDoor();

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", ['allowed' => []])->assertOk();
    $this->deleteJson("/api/channels/{$channel->id}/space/locks/door-1")->assertOk();

    expect(SideSpaceLock::count())->toBe(0);

    // Already unlocked: the caller asked for a state and got it. Racing two people pressing
    // unlock shouldn't make one of them wrong.
    $this->deleteJson("/api/channels/{$channel->id}/space/locks/door-1")->assertOk();
});

it('lets a room owner unlock any lock on their own room', function () {
    [$owner, $server, $channel] = spaceWithDoor();
    $alice = spaceMember($server, 'Alice');

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/rooms/study", ['owner_ids' => [$alice->id]])->assertOk();
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", ['allowed' => []])->assertOk();

    /*
     * Including one the server owner set. Administering a room is about the *room*, not about
     * who happened to turn the key — a room owner who couldn't undo a lock on their own door
     * would have to go and ask, which is the situation putting somebody in charge exists to
     * avoid. Note this is the one place the two lists differ from the two powers: Alice may
     * remove this lock, but it won't appear in her list, which shows only what she set.
     */
    Passport::actingAs($alice);
    $this->deleteJson("/api/channels/{$channel->id}/space/locks/door-1")->assertOk();

    expect(SideSpaceLock::count())->toBe(0);
});

it('sends the locks along with the map when the room changes', function () {
    [$owner, , $channel] = spaceWithDoor();

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", ['allowed' => []])->assertOk();

    /*
     * The locks have to reach everybody standing in the room — a client left holding a map with
     * no locks in it would open every door in it. They travel by refetch rather than by
     * broadcast (the map is too big for a websocket frame; see SideSpaceMapUpdated), so what
     * has to be true is that the *read* carries them, unprompted, for anyone in the channel.
     */
    $this->getJson("/api/channels/{$channel->id}/space/map")
        ->assertOk()
        ->assertJsonCount(1, 'data.locks');
});

it('keeps the map broadcast small enough to survive the websocket frame limit', function () {
    [$owner, , $channel] = spaceWithDoor();

    Passport::actingAs($owner);

    /*
     * The reason the event is a ping and not the map. A furnished room's resource runs to tens
     * of kilobytes, well past Reverb's default 10KB `max_message_size` — over which the frame is
     * dropped and the collision grid silently never arrives. Guarded with a generous ceiling:
     * this asserts the payload is *a notification*, not that it is any particular size.
     */
    $event = new \App\Events\SideSpaceMapUpdated($channel->spaceMap()->sole());

    expect($event->broadcastWith())->toHaveKeys(['id', 'channel_id', 'updated_at'])
        ->and(strlen(json_encode($event->broadcastWith())))->toBeLessThan(1_000);
});

it('does not let the server owner walk through a lock somebody else set', function () {
    [$owner, $server, $channel] = spaceWithDoor();
    $alice = spaceMember($server, 'Alice');

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/rooms/study", ['owner_ids' => [$alice->id]])->assertOk();

    Passport::actingAs($alice);
    $response = $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", ['allowed' => []])->assertOk();

    /*
     * Owning the server is authority over the rules, not a passkey to the rooms. They can still
     * *unlock* this door — but that is an act, and it leaves the lock visibly gone. A standing
     * key would be a silent exception, and a lock with one of those in it is not a lock.
     */
    expect($response->json('data.locks.0.allowed'))->not->toContain($owner->id);
});

it('still lets the server owner through a lock they set themselves', function () {
    [$owner, , $channel] = spaceWithDoor();

    Passport::actingAs($owner);
    $response = $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", ['allowed' => []])->assertOk();

    // As the creator, on the same rule everybody else gets — not as the server's owner.
    expect($response->json('data.locks.0.allowed'))->toBe([$owner->id]);
});

// --- the lists (requirements 4 and 5) ---

it('shows the server owner every lock in the space', function () {
    [$owner, $server, $channel, $map] = spaceWithDoor();
    $alice = spaceMember($server, 'Alice');
    $map->update(['objects' => [
        ...$map->objects,
        ['id' => 'door-2', 'kind' => 'door', 'x' => 8, 'y' => 8, 'facing' => 'down'],
    ]]);

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/rooms/study", ['owner_ids' => [$alice->id]])->assertOk();
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-2", ['allowed' => []])->assertOk();

    Passport::actingAs($alice);
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", ['allowed' => []])->assertOk();

    Passport::actingAs($owner);
    $response = $this->getJson("/api/channels/{$channel->id}/space/locks")->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('can_manage_rooms'))->toBeTrue();
});

it('shows a room owner only the locks they set themselves', function () {
    [$owner, $server, $channel, $map] = spaceWithDoor();
    $alice = spaceMember($server, 'Alice');
    $map->update(['objects' => [
        ...$map->objects,
        ['id' => 'door-2', 'kind' => 'door', 'x' => 8, 'y' => 8, 'facing' => 'down'],
    ]]);

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/rooms/study", ['owner_ids' => [$alice->id]])->assertOk();
    // The server owner's own lock, on a door out in the open.
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-2", ['allowed' => []])->assertOk();

    Passport::actingAs($alice);
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", ['allowed' => []])->assertOk();

    $response = $this->getJson("/api/channels/{$channel->id}/space/locks")->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.object_id'))->toBe('door-1')
        ->and($response->json('data.0.room'))->toBe('The study')
        ->and($response->json('data.0.mine'))->toBeTrue()
        ->and($response->json('can_manage_rooms'))->toBeFalse()
        ->and($response->json('my_rooms'))->toBe(['study']);
});

it('shows a plain member nothing', function () {
    [$owner, $server, $channel] = spaceWithDoor();
    $bob = spaceMember($server, 'Bob');

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", ['allowed' => []])->assertOk();

    Passport::actingAs($bob);
    $response = $this->getJson("/api/channels/{$channel->id}/space/locks")->assertOk();

    // An empty list, not a 403: "you have locked nothing" is true and lets the panel open.
    expect($response->json('data'))->toBe([])
        ->and($response->json('my_rooms'))->toBe([]);
});

it('flags a lock whose door has been taken out of the wall rather than hiding it', function () {
    [$owner, , $channel, $map] = spaceWithDoor();

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", ['allowed' => []])->assertOk();

    // Somebody rebuilds the wall without the door.
    $map->update(['objects' => []]);

    $response = $this->getJson("/api/channels/{$channel->id}/space/locks")->assertOk();

    // Still listed, and marked absent — a row nobody can see is a row nobody can tidy up.
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.present'))->toBeFalse();
});

// --- the property the whole design rests on ---

it('leaves locks and room owners alone when a member saves the map', function () {
    [$owner, $server, $channel] = spaceWithDoor();
    $alice = spaceMember($server, 'Alice');
    $bob = spaceMember($server, 'Bob');

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/rooms/study", ['owner_ids' => [$alice->id]])->assertOk();

    Passport::actingAs($alice);
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", ['allowed' => []])->assertOk();

    /*
     * Bob rebuilds the room from scratch. He may — building is open to every member — and that
     * is exactly why none of this lives in the payload he just sent. If a map save could clear a
     * lock, every other rule in this file would be theatre.
     */
    Passport::actingAs($bob);
    $this->putJson("/api/channels/{$channel->id}/space/map", validMapPayload())->assertOk();

    expect(SideSpaceLock::count())->toBe(1)
        ->and(SideSpaceRoom::where('zone_id', 'study')->value('owner_id'))->toBe($alice->id);
});

// --- passwords ---

/**
 * The other half of a lock: letting in people you couldn't have named in advance.
 *
 * A key-holder list only works when the owner knows who's coming. A password is what a room with
 * a pinned "the code is BADGER" message actually needs, and the properties worth pinning down
 * are that the phrase never leaves the server, that a wrong one changes nothing, and that
 * changing it puts everybody back outside.
 */
it('lets somebody with no key in when they know the password', function () {
    [$owner, $server, $channel] = spaceWithDoor();
    $alice = spaceMember($server, 'Alice');

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", [
        'allowed' => [],
        'password' => 'badger',
    ])->assertOk();

    Passport::actingAs($alice);
    $response = $this->postJson("/api/channels/{$channel->id}/space/locks/door-1/enter", ['password' => 'badger'])
        ->assertOk();

    // The door now opens for her on every screen in the room — which is what the resolved
    // key-holder list riding along with the map means.
    expect($response->json('data.locks.0.allowed'))->toContain($alice->id)
        ->and(SideSpaceLock::first()->passed)->toBe([$alice->id]);
});

it('never sends the password itself, only the fact of one', function () {
    [$owner, $server, $channel] = spaceWithDoor();

    Passport::actingAs($owner);
    $response = $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", [
        'allowed' => [],
        'password' => 'badger',
    ])->assertOk();

    expect($response->json('data.locks.0.has_password'))->toBeTrue()
        ->and(json_encode($response->json()))->not->toContain('badger')
        ->and(SideSpaceLock::first()->password)->not->toBe('badger');
});

it('turns a wrong password away without changing the door', function () {
    [$owner, $server, $channel] = spaceWithDoor();
    $alice = spaceMember($server, 'Alice');

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", [
        'allowed' => [],
        'password' => 'badger',
    ])->assertOk();

    Passport::actingAs($alice);
    $this->postJson("/api/channels/{$channel->id}/space/locks/door-1/enter", ['password' => 'otter'])
        ->assertStatus(422);

    expect(SideSpaceLock::first()->passed ?? [])->toBe([]);
});

it('has nothing to say at a door with no password on it', function () {
    [$owner, $server, $channel] = spaceWithDoor();
    $alice = spaceMember($server, 'Alice');

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", ['allowed' => []])->assertOk();

    Passport::actingAs($alice);
    $this->postJson("/api/channels/{$channel->id}/space/locks/door-1/enter", ['password' => 'badger'])
        ->assertStatus(404);
});

it('shuts out everybody who knew the old password when it changes', function () {
    [$owner, $server, $channel] = spaceWithDoor();
    $alice = spaceMember($server, 'Alice');

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", [
        'allowed' => [],
        'password' => 'badger',
    ])->assertOk();

    Passport::actingAs($alice);
    $this->postJson("/api/channels/{$channel->id}/space/locks/door-1/enter", ['password' => 'badger'])->assertOk();

    // Changing it is the only thing that can take a password-holder's key away, so it has to.
    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", [
        'allowed' => [],
        'password' => 'otter',
    ])->assertOk();

    expect(SideSpaceLock::first()->passed)->toBe([])
        ->and(Doors::keyholders($channel->spaceMap, SideSpaceLock::first()))->not->toContain($alice->id);
});

it('leaves the password alone when only the keys are edited', function () {
    [$owner, $server, $channel] = spaceWithDoor();
    $alice = spaceMember($server, 'Alice');
    $bob = spaceMember($server, 'Bob');

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", [
        'allowed' => [],
        'password' => 'badger',
    ])->assertOk();

    Passport::actingAs($alice);
    $this->postJson("/api/channels/{$channel->id}/space/locks/door-1/enter", ['password' => 'badger'])->assertOk();

    // Handing Bob a key says nothing about the password, and must not clear it — nor forget
    // that Alice already talked her way in.
    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", ['allowed' => [$bob->id]])->assertOk();

    expect(SideSpaceLock::first()->hasPassword())->toBeTrue()
        ->and(SideSpaceLock::first()->passed)->toBe([$alice->id]);
});

it('takes the password off, and everybody who used it with it', function () {
    [$owner, $server, $channel] = spaceWithDoor();
    $alice = spaceMember($server, 'Alice');

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", [
        'allowed' => [],
        'password' => 'badger',
    ])->assertOk();

    Passport::actingAs($alice);
    $this->postJson("/api/channels/{$channel->id}/space/locks/door-1/enter", ['password' => 'badger'])->assertOk();

    Passport::actingAs($owner);
    $response = $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", [
        'allowed' => [],
        'password' => null,
    ])->assertOk();

    expect($response->json('data.locks.0.has_password'))->toBeFalse()
        ->and($response->json('data.locks.0.allowed'))->not->toContain($alice->id);
});

it('refuses a password too short to be one', function () {
    [$owner, $server, $channel] = spaceWithDoor();

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", [
        'allowed' => [],
        'password' => 'ab',
    ])->assertStatus(422);
});

it('only lets members of the server try the password at all', function () {
    [$owner, $server, $channel] = spaceWithDoor();
    $stranger = User::factory()->create();

    Passport::actingAs($owner);
    $this->putJson("/api/channels/{$channel->id}/space/locks/door-1", [
        'allowed' => [],
        'password' => 'badger',
    ])->assertOk();

    Passport::actingAs($stranger);
    $this->postJson("/api/channels/{$channel->id}/space/locks/door-1/enter", ['password' => 'badger'])
        ->assertForbidden();
});
