<?php

use App\Models\ArpgCharacter;
use App\Models\User;
use App\Models\VoiceParticipant;
use App\Services\Games\ArpgGame;
use Laravel\Passport\Passport;

/*
 * The Labyrinth: the dungeon crawl, and the one thing in it the server actually owns.
 *
 * The fight isn't tested here, because the fight isn't here — it's a canvas engine on each
 * client, generated from a seed. What the server is responsible for is the *durable* half, and
 * that's what these cover: that a hero survives the run, that experience and gold land on the
 * character rather than on the room, that a client can't award itself a million levels, and that
 * a party sees each other's levels but not each other's bags.
 */

/**
 * A Side Space with `$count` people standing in it.
 *
 * Its own copy rather than SpaceGameTest's, because a helper shared between Pest files is a
 * load-order dependency waiting to bite.
 *
 * @return array{0: \App\Models\Channel, 1: array<int, User>}
 */
function dungeonRoom(int $count = 1): array
{
    [$owner, $server, $channel] = ownerWithSpaceChannel();

    $users = [$owner];
    for ($i = 1; $i < $count; $i++) {
        $user = User::factory()->create();
        $server->members()->attach($user->id, ['role' => 'member']);
        $users[] = $user;
    }

    foreach ($users as $user) {
        VoiceParticipant::create([
            'channel_id' => $channel->id,
            'user_id' => $user->id,
            'last_seen_at' => now(),
        ]);
    }

    return [$channel, $users];
}

/** Open a portal and hand back the room, the players, and the run as its opener sees it. */
function openedRun(int $count = 1): array
{
    [$channel, $users] = dungeonRoom($count);

    Passport::actingAs($users[0]);
    $game = test()->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'arpg'])
        ->assertOk()
        ->json('data.game');

    return [$channel, $users, $game];
}

// --- opening a run ---

it('opens a portal at once, alone, with a hero rolled on the spot', function () {
    [, $users, $game] = openedRun();

    expect($game['status'])->toBe('running')
        ->and($game['state']['depth'])->toBe(1)
        ->and($game['state']['seed'])->toBeGreaterThan(0)
        // Nobody has to fill in a form before their first dungeon.
        ->and($game['state']['me']['name'])->toBe($users[0]->name)
        ->and($game['state']['me']['level'])->toBe(1);

    expect(ArpgCharacter::where('user_id', $users[0]->id)->count())->toBe(1);
});

it('seats you with the hero you last chose', function () {
    [$channel, $users] = dungeonRoom();

    Passport::actingAs($users[0]);
    $this->postJson('/api/arpg/characters', ['name' => 'Ancarnia', 'class' => 'mage'])->assertCreated();
    $this->postJson('/api/arpg/characters', ['name' => 'Grond', 'class' => 'swordsman'])->assertCreated();

    // Grond was rolled last, so Grond is who walks in.
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'arpg'])
        ->assertOk()
        ->assertJsonPath('data.game.state.me.name', 'Grond')
        ->assertJsonPath('data.game.state.me.class', 'swordsman');
});

it('lets a second player drop into the run with their own hero', function () {
    [$channel, $users] = dungeonRoom(2);

    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'arpg'])->assertOk();

    Passport::actingAs($users[1]);
    $this->postJson('/api/arpg/characters', ['name' => 'Fenn', 'class' => 'thief'])->assertCreated();

    $game = $this->postJson("/api/channels/{$channel->id}/space/game/join")
        ->assertOk()
        ->json('data.game');

    expect($game['state']['me']['name'])->toBe('Fenn')
        ->and($game['state']['players'])->toHaveCount(2);
});

// --- what the server actually owns ---

it('banks experience and gold on the hero, levelling them as it goes', function () {
    [$channel, $users] = dungeonRoom();

    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'arpg'])->assertOk();

    // 400xp is level 3 on a 100·n² curve: enough to prove the curve, not enough to hit the cap.
    $this->postJson("/api/channels/{$channel->id}/space/game/act", [
        'action' => 'progress',
        'payload' => ['xp' => 400, 'gold' => 120],
    ])
        ->assertOk()
        ->assertJsonPath('data.game.state.me.level', 3)
        ->assertJsonPath('data.game.state.me.gold', 120);

    $character = ArpgCharacter::where('user_id', $users[0]->id)->first();

    // The row is the truth; what's in the run's state is a copy for the party to look at.
    expect($character->xp)->toBe(400)
        ->and($character->level)->toBe(3)
        // Four points a level to spend, from level 1 to 3.
        ->and($character->stats['unspent'])->toBe(8);
});

it('refuses to believe a client that awards itself a million experience', function () {
    [$channel, $users] = dungeonRoom();

    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'arpg'])->assertOk();

    $this->postJson("/api/channels/{$channel->id}/space/game/act", [
        'action' => 'progress',
        'payload' => ['xp' => 999_999_999, 'gold' => 999_999_999],
    ])->assertOk();

    $character = ArpgCharacter::where('user_id', $users[0]->id)->first();

    // Clamped to one call's worth. Cheating is possible; cheating *fast* isn't.
    expect($character->xp)->toBe(5_000)
        ->and($character->gold)->toBe(5_000);
});

it('takes an item off the floor, sanitising whatever the client rolled', function () {
    [$channel, $users] = dungeonRoom();

    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'arpg'])->assertOk();

    $game = $this->postJson("/api/channels/{$channel->id}/space/game/act", [
        'action' => 'loot',
        'payload' => ['item' => [
            'name' => 'Jagged Axe of the Bear',
            'slot' => 'weapon',
            'rarity' => 'rare',
            'ilvl' => 3,
            // A believable affix, an absurd one, and one that isn't a thing.
            'affixes' => ['damage' => 7, 'life' => 99_999, 'luck' => 5],
        ]],
    ])->assertOk()->json('data.game');

    $item = $game['state']['me']['inventory'][0];

    expect($item['name'])->toBe('Jagged Axe of the Bear')
        ->and($item['affixes']['damage'])->toBe(7)
        ->and($item['affixes']['life'])->toBe(999)
        ->and($item['affixes'])->not->toHaveKey('luck');
});

it('refuses an item that is not one', function () {
    [$channel, $users] = dungeonRoom();

    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'arpg'])->assertOk();

    $this->postJson("/api/channels/{$channel->id}/space/game/act", [
        'action' => 'loot',
        'payload' => ['item' => ['name' => 'Excalibur', 'slot' => 'trousers']],
    ])->assertStatus(422);
});

it('wears an item, and puts what it replaced back in the bag', function () {
    [$channel, $users] = dungeonRoom();

    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'arpg'])->assertOk();

    foreach (['Short Sword', 'Long Sword'] as $name) {
        $this->postJson("/api/channels/{$channel->id}/space/game/act", [
            'action' => 'loot',
            'payload' => ['item' => ['name' => $name, 'slot' => 'weapon', 'rarity' => 'common', 'ilvl' => 1, 'affixes' => ['damage' => 3]]],
        ])->assertOk();
    }

    $this->postJson("/api/channels/{$channel->id}/space/game/act", ['action' => 'equip', 'payload' => ['index' => 0]])
        ->assertOk()
        ->assertJsonPath('data.game.state.me.equipment.weapon.name', 'Short Sword');

    // The swap: the second sword goes on, the first comes off and is still carried.
    $game = $this->postJson("/api/channels/{$channel->id}/space/game/act", ['action' => 'equip', 'payload' => ['index' => 0]])
        ->assertOk()
        ->json('data.game');

    expect($game['state']['me']['equipment']['weapon']['name'])->toBe('Long Sword')
        ->and($game['state']['me']['inventory'])->toHaveCount(1)
        ->and($game['state']['me']['inventory'][0]['name'])->toBe('Short Sword');
});

it('costs gold to die, and ends a solo run when nobody is left standing', function () {
    [$channel, $users] = dungeonRoom();

    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'arpg'])->assertOk();
    $this->postJson("/api/channels/{$channel->id}/space/game/act", [
        'action' => 'progress',
        'payload' => ['xp' => 0, 'gold' => 400],
    ])->assertOk();

    $this->postJson("/api/channels/{$channel->id}/space/game/act", ['action' => 'died'])
        ->assertOk()
        // A quarter of the purse, and the run is over — there's nobody to pick you up.
        ->assertJsonPath('data.game.state.me.gold', 300)
        ->assertJsonPath('data.game.state.winner', 'dungeon')
        ->assertJsonPath('data.game.status', 'ended');
});

it('keeps a party run going while somebody is still standing', function () {
    [$channel, $users] = dungeonRoom(2);

    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'arpg'])->assertOk();
    Passport::actingAs($users[1]);
    $this->postJson("/api/channels/{$channel->id}/space/game/join")->assertOk();

    $this->postJson("/api/channels/{$channel->id}/space/game/act", ['action' => 'died'])
        ->assertOk()
        ->assertJsonPath('data.game.state.winner', null);

    // …and a companion can put them back on their feet.
    $this->postJson("/api/channels/{$channel->id}/space/game/act", ['action' => 'revive'])
        ->assertOk()
        ->assertJsonPath("data.game.state.players.{$users[1]->id}.alive", true);
});

it('descends, remembers the deepest floor, and ends when the labyrinth runs out', function () {
    [$channel, $users] = dungeonRoom();

    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'arpg'])->assertOk();

    $this->postJson("/api/channels/{$channel->id}/space/game/act", ['action' => 'descend'])
        ->assertOk()
        ->assertJsonPath('data.game.state.depth', 2);

    expect(ArpgCharacter::where('user_id', $users[0]->id)->first()->depth)->toBe(2);

    // All the way down. The floor below the last one is daylight.
    for ($depth = 2; $depth <= ArpgGame::MAX_DEPTH; $depth++) {
        $this->postJson("/api/channels/{$channel->id}/space/game/act", ['action' => 'descend'])->assertOk();
    }

    $this->getJson("/api/channels/{$channel->id}/space/game")
        ->assertJsonPath('data.game.state.winner', 'party')
        ->assertJsonPath('data.game.status', 'ended');
});

it('shows the party your level but not your bag', function () {
    [$channel, $users] = dungeonRoom(2);

    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'arpg'])->assertOk();
    $this->postJson("/api/channels/{$channel->id}/space/game/act", [
        'action' => 'loot',
        'payload' => ['item' => ['name' => 'Ancient Ring', 'slot' => 'ring', 'rarity' => 'unique', 'ilvl' => 2, 'affixes' => ['magic' => 9]]],
    ])->assertOk();

    Passport::actingAs($users[1]);
    $this->postJson("/api/channels/{$channel->id}/space/game/join")->assertOk();

    $game = $this->getJson("/api/channels/{$channel->id}/space/game")->json('data.game');

    // What a companion is shown about you: name, class, level, and whether you're upright.
    expect($game['state']['players'][$users[0]->id])
        ->toHaveKeys(['name', 'class', 'level', 'alive'])
        ->and($game['state']['players'][$users[0]->id])->not->toHaveKey('inventory')
        // `me` is your own sheet, and the newcomer's bag is empty.
        ->and($game['state']['me']['inventory'])->toBe([]);
});

it('lets a hero walk out with everything they earned', function () {
    [$channel, $users] = dungeonRoom();

    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'arpg'])->assertOk();
    $this->postJson("/api/channels/{$channel->id}/space/game/act", [
        'action' => 'progress',
        'payload' => ['xp' => 900, 'gold' => 50],
    ])->assertOk();

    $this->postJson("/api/channels/{$channel->id}/space/game/act", ['action' => 'leave'])
        ->assertOk()
        // The last one out closes the portal.
        ->assertJsonPath('data.game.state.winner', 'empty');

    $character = ArpgCharacter::where('user_id', $users[0]->id)->first();
    expect($character->xp)->toBe(900)->and($character->gold)->toBe(50);
});

it('refuses moves from somebody who is not in the run', function () {
    [$channel, $users] = dungeonRoom(2);

    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'arpg'])->assertOk();

    // In the room, watching, but never stepped through the portal.
    Passport::actingAs($users[1]);
    $this->postJson("/api/channels/{$channel->id}/space/game/act", [
        'action' => 'progress',
        'payload' => ['xp' => 100],
    ])->assertStatus(422);
});

// --- the roster ---

it('rolls, lists, selects and retires heroes', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->postJson('/api/arpg/characters', ['name' => 'Ancarnia', 'class' => 'mage'])
        ->assertCreated()
        ->assertJsonPath('data.class', 'mage')
        // The class is the starting attributes — a mage opens with 38 magic — and its first skill.
        ->assertJsonPath('data.stats.magic', 38)
        ->assertJsonPath('data.skills.firebolt', 1);

    $second = $this->postJson('/api/arpg/characters', ['name' => 'Grond', 'class' => 'swordsman'])->json('data.id');

    $this->getJson('/api/arpg/characters')->assertOk()->assertJsonCount(2, 'data');

    // Selecting is only "I played this one most recently", which is what puts it on top.
    $this->postJson("/api/arpg/characters/{$second}/select")->assertOk();
    $this->getJson('/api/arpg/characters')->assertJsonPath('data.0.name', 'Grond');

    $this->deleteJson("/api/arpg/characters/{$second}")->assertNoContent();
    $this->getJson('/api/arpg/characters')->assertJsonCount(1, 'data');
});

it('refuses a duplicate name, an unknown class, and a seventh hero', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->postJson('/api/arpg/characters', ['name' => 'Grond', 'class' => 'swordsman'])->assertCreated();
    $this->postJson('/api/arpg/characters', ['name' => 'Grond', 'class' => 'thief'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->postJson('/api/arpg/characters', ['name' => 'Bard', 'class' => 'bard'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('class');

    for ($i = 2; $i <= 6; $i++) {
        $this->postJson('/api/arpg/characters', ['name' => "Hero {$i}", 'class' => 'thief'])->assertCreated();
    }

    $this->postJson('/api/arpg/characters', ['name' => 'One Too Many', 'class' => 'thief'])->assertStatus(422);
});

it('keeps one player out of another player’s heroes', function () {
    $mine = ArpgCharacter::factory()->create();

    Passport::actingAs(User::factory()->create());
    $this->postJson("/api/arpg/characters/{$mine->id}/select")->assertForbidden();
    $this->deleteJson("/api/arpg/characters/{$mine->id}")->assertForbidden();
    $this->getJson('/api/arpg/characters')->assertJsonCount(0, 'data');
});

// --- skills, points, and inheritance ---

/** A hero of `$class` at `$level`, with `$points` to spend. */
function heroWith(string $class, int $level = 10, int $points = 5): array
{
    $user = User::factory()->create();
    Passport::actingAs($user);

    $character = app(ArpgGame::class)->roll($user, 'Test Hero', $class);
    $character->update(['level' => $level, 'skill_points' => $points]);

    return [$user, $character];
}

it('serves the skill catalogue with the rules the client needs', function () {
    Passport::actingAs(User::factory()->create());

    $res = $this->getJson('/api/arpg/skills')->assertOk();

    expect($res->json('meta.foreign_limits.1'))->toBe(3)
        ->and($res->json('meta.foreign_limits.2'))->toBe(3)
        ->and($res->json('meta.jobs.wizard.advances_from'))->toBe('mage')
        ->and($res->json('meta.classes'))->toContain('necromancer', 'druid', 'priest', 'archer')
        // Every skill is a kind plus numbers — the engine implements six verbs, not this list.
        ->and(collect($res->json('data'))->pluck('kind')->unique()->sort()->values()->all())
        ->toBe(['buff', 'heal', 'melee', 'nova', 'projectile', 'summon']);
});

it('gives a new hero their class’s opening skill and nothing else', function () {
    [, $character] = heroWith('necromancer', level: 1, points: 0);

    expect($character->skills)->toBe(['bone_spear' => 1])
        ->and($character->skill_points)->toBe(0);
});

it('spends a point to learn and then to deepen a skill', function () {
    [, $character] = heroWith('mage');

    $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => 'fireball'])
        ->assertOk()
        ->assertJsonPath('data.skills.fireball', 1)
        ->assertJsonPath('data.skill_points', 4);

    // A second point in the same skill deepens it rather than learning it twice.
    $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => 'fireball'])
        ->assertOk()
        ->assertJsonPath('data.skills.fireball', 2)
        ->assertJsonPath('data.skill_points', 3);
});

it('refuses a skill the hero is too low for, and one that does not exist', function () {
    [, $character] = heroWith('mage', level: 2);

    // Fireball opens at character level 6.
    $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => 'fireball'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('skill');

    $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => 'summon_dragon'])
        ->assertStatus(422);
});

it('refuses to spend a point that has not been earned', function () {
    [, $character] = heroWith('archer', points: 0);

    $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => 'multishot'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('skill');
});

it('lets a hero inherit skills from other classes, up to three', function () {
    [, $character] = heroWith('swordsman', points: 8);

    // Three borrowed: a prayer, a bow and a bolt on a swordsman. All allowed.
    foreach (['heal', 'arrow_shot', 'firebolt'] as $skill) {
        $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => $skill])->assertOk();
    }

    // The fourth is one class too many.
    $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => 'bone_spear'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('skill');

    // …but their own class is never capped.
    $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => 'bash'])
        ->assertOk()
        ->assertJsonPath('data.skills.bash', 1);
});

it('counts distinct borrowed skills, not points spent on them', function () {
    [, $character] = heroWith('swordsman', points: 9);

    // Three borrowed skills, then three more points poured into one of them. Still three.
    foreach (['heal', 'arrow_shot', 'firebolt', 'heal', 'heal', 'heal'] as $skill) {
        $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => $skill])->assertOk();
    }

    $character->refresh();

    // Cleave (their own, free at birth) plus the three borrowed. Heal is deeper, not wider.
    expect($character->skills['heal'])->toBe(4)
        ->and($character->skills)->toHaveCount(4);

    $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => 'bone_spear'])
        ->assertStatus(422);
});

it('honours a different inheritance limit from config', function () {
    config(['arpg.foreign_skill_limits' => [1 => 1, 2 => 3]]);
    [, $character] = heroWith('swordsman', points: 4);

    $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => 'heal'])->assertOk();
    $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => 'firebolt'])
        ->assertStatus(422);
});

it('stops at the skill ceiling', function () {
    config(['arpg.max_skill_level' => 2]);
    [, $character] = heroWith('mage', points: 6);

    // Firebolt starts at 1 (it's the mage's opening skill), so one more point tops it out.
    $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => 'firebolt'])->assertOk();
    $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => 'firebolt'])
        ->assertStatus(422);
});

it('earns a skill point per level, and can spend it without leaving the dungeon', function () {
    [$channel, $users] = dungeonRoom();

    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'arpg'])->assertOk();

    // Levels 1 → 3 is two levels, so two skill points (and eight attribute points).
    $this->postJson("/api/channels/{$channel->id}/space/game/act", [
        'action' => 'progress',
        'payload' => ['xp' => 400],
    ])->assertOk()->assertJsonPath('data.game.state.me.skill_points', 2);

    // Spending one mid-run: you levelled two rooms ago and want the skill now.
    $this->postJson("/api/channels/{$channel->id}/space/game/act", [
        'action' => 'learn',
        'payload' => ['skill' => 'heal'],
    ])
        ->assertOk()
        ->assertJsonPath('data.game.state.me.skill_points', 1)
        ->assertJsonPath('data.game.state.me.skills.heal', 1);

    // A hero nobody rolled by hand is still a real class with a real opening skill.
    expect(ArpgCharacter::where('user_id', $users[0]->id)->first()->class)
        ->toBe(ArpgGame::DEFAULT_CLASS);
});

it('keeps one player from spending another player’s points', function () {
    $mine = ArpgCharacter::factory()->create(['skill_points' => 5, 'level' => 10, 'class' => 'mage']);

    Passport::actingAs(User::factory()->create());
    $this->postJson("/api/arpg/characters/{$mine->id}/skills", ['skill' => 'firebolt'])->assertForbidden();
});

// --- job advancement ---

it('advances a hero to their second job at thirty, with its opening skill free', function () {
    [, $character] = heroWith('mage', level: 30, points: 0);

    $this->postJson("/api/arpg/characters/{$character->id}/advance")
        ->assertOk()
        ->assertJsonPath('data.job', 'wizard')
        ->assertJsonPath('data.job_name', 'Wizard')
        // The class never moves — a wizard is still a mage by birth.
        ->assertJsonPath('data.class', 'mage')
        // Advancement hands you something to press, not homework.
        ->assertJsonPath('data.skills.chain_lightning', 1);
});

it('refuses to advance a hero who has not earned it', function () {
    [, $character] = heroWith('thief', level: 29);

    $this->postJson("/api/arpg/characters/{$character->id}/advance")
        ->assertStatus(422)
        ->assertJsonValidationErrors('job');
});

it('refuses to advance past the end of the line', function () {
    [, $character] = heroWith('archer', level: 50);

    $this->postJson("/api/arpg/characters/{$character->id}/advance")->assertOk();
    // Ranger is as far as the tree goes today.
    $this->postJson("/api/arpg/characters/{$character->id}/advance")
        ->assertStatus(422)
        ->assertJsonValidationErrors('job');
});

it('honours a different advancement level from config', function () {
    config(['arpg.advancement_levels' => [2 => 12]]);
    [, $character] = heroWith('priest', level: 12);

    $this->postJson("/api/arpg/characters/{$character->id}/advance")
        ->assertOk()
        ->assertJsonPath('data.job', 'bishop');
});

it('keeps second-job skills behind the second job, however high the level', function () {
    // Level 45 and never advanced: the tier is bought with the job, not with levels.
    [, $character] = heroWith('mage', level: 45, points: 5);

    $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => 'meteor'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('skill');

    // Borrowing somebody else's second-job skill is refused for exactly the same reason.
    $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => 'whirlwind'])
        ->assertStatus(422);

    $this->postJson("/api/arpg/characters/{$character->id}/advance")->assertOk();
    $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => 'meteor'])
        ->assertOk()
        ->assertJsonPath('data.skills.meteor', 1);
});

it('still counts first-job skills as its own after advancing', function () {
    [, $character] = heroWith('mage', level: 40, points: 6);

    $this->postJson("/api/arpg/characters/{$character->id}/advance")->assertOk();

    // A wizard hasn't forgotten Firebolt, and Fireball is still theirs to deepen — neither
    // touches the borrowed allowance.
    $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => 'fireball'])->assertOk();

    foreach (['heal', 'arrow_shot', 'cleave'] as $borrowed) {
        $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => $borrowed])->assertOk();
    }

    // Three borrowed at tier 1 is the cap.
    $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => 'backstab'])
        ->assertStatus(422);
});

it('gives a fresh borrowing allowance at the second tier', function () {
    [, $character] = heroWith('mage', level: 45, points: 12);

    $this->postJson("/api/arpg/characters/{$character->id}/advance")->assertOk();

    // Spend the first-tier allowance completely.
    foreach (['heal', 'arrow_shot', 'cleave'] as $borrowed) {
        $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => $borrowed])->assertOk();
    }
    $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => 'backstab'])->assertStatus(422);

    // The second-tier allowance is untouched by any of that: three more, from other lines.
    foreach (['whirlwind', 'mass_heal', 'arrow_storm'] as $borrowed) {
        $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => $borrowed])->assertOk();
    }

    $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => 'shadow_strike'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('skill');
});

it('honours a different second-tier limit from config', function () {
    config(['arpg.foreign_skill_limits' => [1 => 3, 2 => 1]]);
    [, $character] = heroWith('mage', level: 45, points: 8);

    $this->postJson("/api/arpg/characters/{$character->id}/advance")->assertOk();
    $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => 'whirlwind'])->assertOk();
    $this->postJson("/api/arpg/characters/{$character->id}/skills", ['skill' => 'mass_heal'])
        ->assertStatus(422);
});

it('advances mid-run, and tells the room', function () {
    [$channel, $users] = dungeonRoom();

    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'arpg'])->assertOk();

    // 85,000xp is level 30 on the 100·n² curve, banked a capped call at a time.
    for ($i = 0; $i < 17; $i++) {
        $this->postJson("/api/channels/{$channel->id}/space/game/act", [
            'action' => 'progress',
            'payload' => ['xp' => 5000],
        ])->assertOk();
    }

    $game = $this->getJson("/api/channels/{$channel->id}/space/game")->json('data.game');
    expect($game['state']['me']['level'])->toBe(30)
        ->and($game['state']['me']['advance_to']['ready'])->toBeTrue()
        ->and($game['state']['me']['advance_to']['name'])->toBe('Knight');

    $this->postJson("/api/channels/{$channel->id}/space/game/act", ['action' => 'advance'])
        ->assertOk()
        ->assertJsonPath('data.game.state.me.job', 'knight')
        // The party is told: advancing is worth being seen doing.
        ->assertJsonPath("data.game.state.players.{$users[0]->id}.job_name", 'Knight');
});
