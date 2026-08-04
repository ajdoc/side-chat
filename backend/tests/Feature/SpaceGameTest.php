<?php

use App\Events\SpaceGameUpdated;
use App\Models\Channel;
use App\Models\SpaceGame;
use App\Models\User;
use App\Models\VoiceParticipant;
use App\Services\Games\GameService;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;
use Tests\Support\OpenTestGame;

/*
 * Games in a Side Space. The framework is generic — propose, vote, act, redact, end — so the
 * tests split the same way: the plumbing (who may propose, when a vote starts a game, what a
 * viewer is allowed to see) tested once and for all, and Among Us's own rules tested as the first
 * game to exercise them.
 */

/**
 * A Side Space with `$count` people standing in it, all members of the server.
 *
 * "Standing in it" is a VoiceParticipant row — the same thing walking into the room creates, and
 * exactly the electorate the game uses. The first user is the one who'll propose.
 *
 * @return array{0: Channel, 1: array<int, User>}
 */
function roomWithPlayers(int $count): array
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

/** Everybody's own view of the game, keyed by user id — the only way to learn secret roles. */
function rolesOf(Channel $channel, array $users): array
{
    $roles = [];
    foreach ($users as $user) {
        Passport::actingAs($user);
        $roles[$user->id] = test()->getJson("/api/channels/{$channel->id}/space/game")
            ->json('data.game.state.my_role');
    }

    return $roles;
}

// --- proposing and voting ---

it('lets someone in the room propose a game, and starts it once the room agrees', function () {
    [$channel, $users] = roomWithPlayers(3);
    [$a, $b] = $users;

    // Proposing counts as a yes, but one of three isn't a majority — still voting.
    Passport::actingAs($a);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'amongus'])
        ->assertOk()
        ->assertJsonPath('data.game.status', 'voting')
        ->assertJsonPath('data.game.vote.yes', 1);

    // A second yes tips it past half the room, and it starts.
    Passport::actingAs($b);
    $this->postJson("/api/channels/{$channel->id}/space/game/vote", ['vote' => true])
        ->assertOk()
        ->assertJsonPath('data.game.status', 'running');
});

it('refuses a game the room is too small for', function () {
    [$channel, $users] = roomWithPlayers(2);

    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'amongus'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');
});

it('forbids someone not in the room from proposing or voting', function () {
    [$channel, $users] = roomWithPlayers(3);

    // A server member who never walked into the room.
    $outsider = User::factory()->create();
    $channel->server->members()->attach($outsider->id, ['role' => 'member']);
    Passport::actingAs($outsider);

    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'amongus'])->assertForbidden();
});

it('rejects an unknown game', function () {
    [$channel, $users] = roomWithPlayers(3);
    Passport::actingAs($users[0]);

    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'hopscotch'])
        ->assertStatus(422);
});

// --- a running game ---

/** Start a 4-player game and hand back the channel, the users, and who ended up the impostor. */
function startedGame(int $count = 4): array
{
    [$channel, $users] = roomWithPlayers($count);

    // One proposes; everyone (including the proposer, harmlessly) votes yes — an unambiguous
    // majority, so it starts.
    Passport::actingAs($users[0]);
    test()->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'amongus']);

    foreach ($users as $user) {
        Passport::actingAs($user);
        test()->postJson("/api/channels/{$channel->id}/space/game/vote", ['vote' => true]);
    }

    $roles = rolesOf($channel, $users);
    $impostorId = array_search('impostor', $roles, true);

    return [$channel, $users, $impostorId, $roles];
}

it('deals exactly one impostor in a small game, and hides it from the crew', function () {
    [$channel, $users, $impostorId, $roles] = startedGame(4);

    expect(collect($roles)->filter(fn ($r) => $r === 'impostor'))->toHaveCount(1);

    // A crewmate looking at the impostor sees a player, not a role.
    $crew = collect($users)->first(fn ($u) => $u->id !== $impostorId);
    Passport::actingAs($crew);
    $this->getJson("/api/channels/{$channel->id}/space/game")
        ->assertOk()
        ->assertJsonPath("data.game.state.players.{$impostorId}.role", null)
        ->assertJsonPath("data.game.state.players.{$crew->id}.role", 'crew');
});

it('lets a crewmate finish a task and moves the shared bar', function () {
    [$channel, $users, $impostorId] = startedGame(4);

    $crew = collect($users)->first(fn ($u) => $u->id !== $impostorId);
    Passport::actingAs($crew);

    $before = $this->getJson("/api/channels/{$channel->id}/space/game")->json('data.game.state');
    $task = $before['my_tasks'][0]['id'];

    $this->postJson("/api/channels/{$channel->id}/space/game/act", [
        'action' => 'complete_task',
        'payload' => ['task' => $task],
    ])->assertOk()->assertJsonPath('data.game.state.task_done', $before['task_done'] + 1);
});

it('only lets the impostor kill, and ends the game when the numbers tip', function () {
    Event::fake([SpaceGameUpdated::class]);
    [$channel, $users, $impostorId] = startedGame(3);

    $crew = collect($users)->filter(fn ($u) => $u->id !== $impostorId)->values();

    // A crewmate can't kill.
    Passport::actingAs($crew[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game/act", [
        'action' => 'kill',
        'payload' => ['target' => $crew[1]->id],
    ])->assertStatus(422);

    // The impostor's kill is on cooldown at the start of the game.
    Passport::actingAs(collect($users)->firstWhere('id', $impostorId));
    $this->postJson("/api/channels/{$channel->id}/space/game/act", [
        'action' => 'kill',
        'payload' => ['target' => $crew[0]->id],
    ])->assertStatus(422);

    // Fast-forward the cooldown by reaching into state, then the kill lands — and in a game of
    // three, one kill leaves the impostor level with the crew, so they win.
    $game = $channel->spaceGame;
    $state = $game->state;
    $state['cooldowns'][$impostorId] = 0;
    $game->update(['state' => $state]);

    $this->postJson("/api/channels/{$channel->id}/space/game/act", [
        'action' => 'kill',
        'payload' => ['target' => $crew[0]->id, 'x' => 3, 'y' => 3],
    ])->assertOk()->assertJsonPath('data.game.state.winner', 'impostor');

    expect($channel->spaceGame()->sole()->status)->toBe('ended');
});

it('runs a meeting and ejects whoever the room votes for', function () {
    [$channel, $users, $impostorId] = startedGame(3);

    // Anyone alive can call a meeting.
    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game/act", ['action' => 'call_meeting'])
        ->assertOk()
        ->assertJsonPath('data.game.state.phase', 'meeting');

    // Everyone votes for the impostor. The last vote auto-resolves the meeting, the impostor is
    // ejected and revealed, and with no impostors left the crew win.
    foreach ($users as $user) {
        Passport::actingAs($user);
        $this->postJson("/api/channels/{$channel->id}/space/game/act", [
            'action' => 'vote',
            'payload' => ['target' => $impostorId],
        ])->assertOk();
    }

    $state = $this->getJson("/api/channels/{$channel->id}/space/game")->json('data.game.state');

    expect($state['winner'])->toBe('crew')
        ->and($state['players'][$impostorId]['role'])->toBe('impostor'); // revealed at the end
});

it('cannot vote outside a meeting', function () {
    [$channel, $users, $impostorId] = startedGame(3);

    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game/act", [
        'action' => 'vote',
        'payload' => ['target' => $impostorId],
    ])->assertStatus(422);
});

it('lets anyone in the room cancel the game', function () {
    [$channel, $users] = startedGame(3);

    Passport::actingAs($users[1]);
    $this->deleteJson("/api/channels/{$channel->id}/space/game")
        ->assertOk()
        ->assertJsonPath('data.game.status', 'ended');
});

it('stops serving an ending once it has had its moment', function () {
    [$channel, $users] = startedGame(3);

    Passport::actingAs($users[1]);
    $this->deleteJson("/api/channels/{$channel->id}/space/game")->assertOk();

    // Straight away it's still on screen — whoever was playing gets to read the result.
    $this->getJson("/api/channels/{$channel->id}/space/game")
        ->assertOk()
        ->assertJsonPath('data.game.status', 'ended');

    // Later, the room is simply a room again: nothing clears the row until the next game is
    // proposed, so without this a refresh would put the result card back up for everyone.
    $this->travel(SpaceGame::ENDED_TTL_SECONDS + 1)->seconds();

    $this->getJson("/api/channels/{$channel->id}/space/game")
        ->assertOk()
        ->assertJsonPath('data.game', null);
});

it('serves the game catalogue to any logged-in user', function () {
    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/space/games')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'amongus');
});

// --- pet battles: the framework's challenge mode, and the game itself ---

/** A challenge from users[0] to users[1], accepted — returns the running battle's channel/users. */
function startedBattle(): array
{
    [$channel, $users] = roomWithPlayers(3);

    $users[0]->update(['space_pet' => 'leafling']); // grass
    $users[1]->update(['space_pet' => 'emberpup']); // fire

    Passport::actingAs($users[0]);
    test()->postJson("/api/channels/{$channel->id}/space/game", [
        'type' => 'petbattle',
        'opponent' => $users[1]->id,
    ])->assertOk()->assertJsonPath('data.game.status', 'voting');

    Passport::actingAs($users[1]);
    test()->postJson("/api/channels/{$channel->id}/space/game/vote", ['vote' => true])
        ->assertOk()->assertJsonPath('data.game.status', 'running');

    return [$channel, $users];
}

it('starts a pet battle when the challenged player accepts', function () {
    [$channel, $users] = startedBattle();

    $state = $this->getJson("/api/channels/{$channel->id}/space/game")->json('data.game.state');

    expect($state['players'])->toHaveCount(2)
        ->and(collect($state['players'])->pluck('element')->all())->toContain('grass', 'fire')
        ->and($state['winner'])->toBeNull()
        ->and($state['players'][0]['hp'])->toBe(100);
});

it('ends the challenge when the challenged player declines', function () {
    [$channel, $users] = roomWithPlayers(3);
    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'petbattle', 'opponent' => $users[1]->id]);

    Passport::actingAs($users[1]);
    $this->postJson("/api/channels/{$channel->id}/space/game/vote", ['vote' => false])
        ->assertOk()
        ->assertJsonPath('data.game.status', 'ended');
});

it('lets only the challenged player answer the challenge', function () {
    [$channel, $users] = roomWithPlayers(3);
    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'petbattle', 'opponent' => $users[1]->id]);

    Passport::actingAs($users[2]);
    $this->postJson("/api/channels/{$channel->id}/space/game/vote", ['vote' => true])->assertStatus(422);
});

it('refuses a challenge to someone who is not in the room', function () {
    [$channel, $users] = roomWithPlayers(3);
    $outsider = User::factory()->create();
    $channel->server->members()->attach($outsider->id, ['role' => 'member']);

    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'petbattle', 'opponent' => $outsider->id])
        ->assertStatus(422)->assertJsonValidationErrors('opponent');
});

it('takes turns, and only lets the active fighter move', function () {
    [$channel, $users] = startedBattle();

    $whoseTurn = $this->getJson("/api/channels/{$channel->id}/space/game")->json('data.game.state.turn');
    $active = collect($users)->firstWhere('id', $whoseTurn);
    $waiting = collect([$users[0], $users[1]])->first(fn ($u) => $u->id !== $whoseTurn);

    Passport::actingAs($waiting);
    $this->postJson("/api/channels/{$channel->id}/space/game/act", ['action' => 'move', 'payload' => ['move' => 'tackle']])
        ->assertStatus(422);

    Passport::actingAs($active);
    $this->postJson("/api/channels/{$channel->id}/space/game/act", ['action' => 'move', 'payload' => ['move' => 'tackle']])
        ->assertOk()
        ->assertJsonPath('data.game.state.turn', $waiting->id);
});

it('runs a battle to a knockout', function () {
    [$channel, $users] = startedBattle();
    $duellists = [$users[0], $users[1]];

    for ($i = 0; $i < 60; $i++) {
        $state = $this->getJson("/api/channels/{$channel->id}/space/game")->json('data.game.state');
        if ($state['winner'] !== null) {
            break;
        }
        $mover = collect($duellists)->firstWhere('id', $state['turn']);
        Passport::actingAs($mover);
        $this->postJson("/api/channels/{$channel->id}/space/game/act", ['action' => 'move', 'payload' => ['move' => 'special']]);
    }

    $final = $this->getJson("/api/channels/{$channel->id}/space/game")->json('data.game');
    expect($final['state']['winner'])->not->toBeNull()
        ->and($final['status'])->toBe('ended');
});

it('lets a fighter forfeit', function () {
    [$channel, $users] = startedBattle();

    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game/act", ['action' => 'forfeit'])
        ->assertOk()
        ->assertJsonPath('data.game.state.winner', $users[1]->id);
});

it('offers pet battle in the catalogue as a challenge', function () {
    Passport::actingAs(User::factory()->create());
    $this->getJson('/api/space/games')
        ->assertOk()
        ->assertJsonFragment(['type' => 'petbattle', 'mode' => 'challenge']);
});

it('tells the whole room when a game is proposed, voted on, or challenged', function () {
    Event::fake([SpaceGameUpdated::class]);
    [$channel, $users] = roomWithPlayers(3);

    // Proposing broadcasts, so the vote card reaches everyone — not just the proposer.
    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'amongus'])->assertOk();
    Event::assertDispatched(SpaceGameUpdated::class);

    // …and so does each vote.
    Passport::actingAs($users[1]);
    $this->postJson("/api/channels/{$channel->id}/space/game/vote", ['vote' => true])->assertOk();
    Event::assertDispatchedTimes(SpaceGameUpdated::class, 2);
});

// --- open games and drop-in co-op ---

/**
 * Put {@see OpenTestGame} in the catalogue for this test.
 *
 * The service is resolved fresh per request, so registering on some instance wouldn't reach the
 * controller — the whole service goes in the container instead, carrying the extra game.
 */
function withOpenGame(): void
{
    // Resolved rather than constructed, so adding a game to the app's own catalogue doesn't
    // break this helper the way spelling out its constructor would.
    $service = app(GameService::class);
    $service->register(new OpenTestGame);
    app()->instance(GameService::class, $service);
}

it('starts an open game the moment it is proposed, with nobody else asked', function () {
    withOpenGame();
    [$channel, $users] = roomWithPlayers(3);

    // Three people in the room and no vote: an open game isn't put to anyone.
    Passport::actingAs($users[0]);
    $game = $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'opentest'])
        ->assertOk()
        ->assertJsonPath('data.game.status', 'running')
        ->assertJsonPath('data.game.vote', null)
        ->json('data.game');

    // And it starts with the opener alone, however many people were standing there.
    expect(array_keys($game['state']['players']))->toBe([$users[0]->id]);
});

it('lets one person play an open game alone in an empty room', function () {
    withOpenGame();
    [$channel, $users] = roomWithPlayers(1);

    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'opentest'])
        ->assertOk()
        ->assertJsonPath('data.game.status', 'running');

    $this->postJson("/api/channels/{$channel->id}/space/game/act", ['action' => 'score'])
        ->assertOk()
        ->assertJsonPath("data.game.state.players.{$users[0]->id}.score", 1);
});

it('lets someone in the room drop into a running open game', function () {
    withOpenGame();
    [$channel, $users] = roomWithPlayers(2);
    [$opener, $latecomer] = $users;

    Passport::actingAs($opener);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'opentest'])->assertOk();

    // The bystander is offered the way in, takes it, and can then play.
    Passport::actingAs($latecomer);
    $this->getJson("/api/channels/{$channel->id}/space/game")->assertJsonPath('data.game.can_join', true);

    $this->postJson("/api/channels/{$channel->id}/space/game/join")
        ->assertOk()
        // Joined, and no longer offered a second seat.
        ->assertJsonPath('data.game.can_join', false)
        ->assertJsonPath("data.game.state.players.{$latecomer->id}.score", 0);

    $this->postJson("/api/channels/{$channel->id}/space/game/act", ['action' => 'score'])->assertOk();
});

it('refuses a second helping, a full game, and an outsider', function () {
    withOpenGame();
    [$channel, $users] = roomWithPlayers(3);

    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'opentest'])->assertOk();

    // Already playing.
    $this->postJson("/api/channels/{$channel->id}/space/game/join")->assertStatus(422);

    // The second seat is the last one — OpenTestGame holds two.
    Passport::actingAs($users[1]);
    $this->postJson("/api/channels/{$channel->id}/space/game/join")->assertOk();

    Passport::actingAs($users[2]);
    $this->getJson("/api/channels/{$channel->id}/space/game")->assertJsonPath('data.game.can_join', false);
    $this->postJson("/api/channels/{$channel->id}/space/game/join")->assertStatus(422);

    // And someone who never walked into the room is not a candidate at all.
    $outsider = User::factory()->create();
    $channel->server->members()->attach($outsider->id, ['role' => 'member']);
    Passport::actingAs($outsider);
    $this->getJson("/api/channels/{$channel->id}/space/game")->assertJsonPath('data.game.can_join', false);
    $this->postJson("/api/channels/{$channel->id}/space/game/join")->assertForbidden();
});

it('refuses to let anyone drop into a game whose roster is closed', function () {
    [$channel, $users] = startedGame();

    // Among Us deals its crew once. A latecomer spectates; there is no seat to take.
    $latecomer = User::factory()->create();
    $channel->server->members()->attach($latecomer->id, ['role' => 'member']);
    VoiceParticipant::create([
        'channel_id' => $channel->id,
        'user_id' => $latecomer->id,
        'last_seen_at' => now(),
    ]);

    Passport::actingAs($latecomer);
    $this->getJson("/api/channels/{$channel->id}/space/game")->assertJsonPath('data.game.can_join', false);
    $this->postJson("/api/channels/{$channel->id}/space/game/join")->assertStatus(422);
});

it('tells the room when somebody joins', function () {
    withOpenGame();
    [$channel, $users] = roomWithPlayers(2);

    Passport::actingAs($users[0]);
    $this->postJson("/api/channels/{$channel->id}/space/game", ['type' => 'opentest'])->assertOk();

    Event::fake([SpaceGameUpdated::class]);
    Passport::actingAs($users[1]);
    $this->postJson("/api/channels/{$channel->id}/space/game/join")->assertOk();
    Event::assertDispatched(SpaceGameUpdated::class);
});
