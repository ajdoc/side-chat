<?php

use App\Models\Channel;
use App\Models\MobaMatch;
use App\Models\MobaMatchPlayer;
use App\Models\MobaProfile;
use App\Models\MobaQueueEntry;
use App\Models\User;
use App\Services\Moba\QueueService;
use App\Services\Moba\RatingService;
use App\Support\Moba\Heroes;
use App\Support\Moba\MatchTicket;
use Laravel\Passport\Passport;

/**
 * The MOBA's metagame — the queue, the ticket and the rating.
 *
 * Nothing here touches the game itself: the simulation is 12,000 lines of Rust with its own
 * suite, and the seam between them is two signed messages. These tests are about the seam and
 * about the decisions PHP actually owns.
 */

function queueUp(User $user, int $size = 1, string $hero = 'ironclad'): void
{
    app(QueueService::class)->join($user, $size, $hero);
}

/**
 * A GET as a given user.
 *
 * The API sits behind `auth:api` — a Passport token, not a session — so `actingAs` alone
 * authenticates against the wrong guard and every request comes back 401.
 */
function passportGet(User $user, string $url): Illuminate\Testing\TestResponse
{
    Passport::actingAs($user);

    return test()->getJson($url);
}

// ── The ticket ──────────────────────────────────────────────────────────────────────────────

it('mints a ticket that verifies and carries the seat', function () {
    $ticket = MatchTicket::mint(42, 7, 1, 3, 'relay');
    $payload = MatchTicket::verify($ticket);

    expect($payload)->not->toBeNull()
        ->and($payload['m'])->toBe(42)
        ->and($payload['u'])->toBe(7)
        ->and($payload['t'])->toBe(1)
        ->and($payload['s'])->toBe(3)
        ->and($payload['h'])->toBe('relay');
});

it('refuses a ticket whose payload has been edited', function () {
    // The whole point of signing it. A player who could change the team field could seat
    // themselves on the other side; one who could change the match id could walk into a game
    // they were never in.
    $ticket = MatchTicket::mint(42, 7, 0, 0, 'ironclad');
    [$payload, $signature] = explode('.', $ticket);

    $forged = rtrim(strtr(base64_encode(json_encode([
        'm' => 42, 'u' => 7, 't' => 1, 's' => 0, 'h' => 'ironclad', 'exp' => now()->timestamp + 60,
    ])), '+/', '-_'), '=');

    expect(MatchTicket::verify($forged.'.'.$signature))->toBeNull();
    expect(MatchTicket::verify($payload.'.'.$signature))->not->toBeNull();
});

it('refuses an expired ticket', function () {
    // Short expiry is what makes a ticket captured from a network tab worthless. A reconnect
    // gets a *new* ticket rather than a long-lived one.
    $ticket = MatchTicket::mint(1, 1, 0, 0, 'ironclad');
    $this->travel(MatchTicket::TTL_SECONDS + 5)->seconds();

    expect(MatchTicket::verify($ticket))->toBeNull();
});

it('refuses malformed tickets without saying which part was wrong', function () {
    foreach (['', 'nonsense', 'a.b.c', 'onlyonepart'] as $bad) {
        expect(MatchTicket::verify($bad))->toBeNull();
    }
});

// ── The queue ───────────────────────────────────────────────────────────────────────────────

it('forms a match once both seats of a 1v1 are filled', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    queueUp($a);
    expect(MobaMatch::count())->toBe(0, 'a match formed with one player in the queue');

    queueUp($b);
    app(QueueService::class)->formMatches();

    expect(MobaMatch::count())->toBe(1)
        ->and(MobaMatchPlayer::count())->toBe(2)
        ->and(MobaQueueEntry::count())->toBe(0, 'the queue still holds players who were seated');
});

it('does not mix players who queued for different sizes', function () {
    // Someone waiting for a 5v5 is not a candidate for a 1v1, however long either has waited.
    queueUp(User::factory()->create(), 1);
    queueUp(User::factory()->create(), 5);
    app(QueueService::class)->formMatches();

    expect(MobaMatch::count())->toBe(0);
});

it('puts the two sides of a match on opposite teams', function () {
    queueUp(User::factory()->create());
    queueUp(User::factory()->create());
    app(QueueService::class)->formMatches();

    $match = MobaMatch::with('players')->first();
    expect($match->players->pluck('team')->sort()->values()->all())->toBe([0, 1]);
    expect($match->players->pluck('slot')->sort()->values()->all())->toBe([0, 1]);
});

it('seats a 5v5 evenly and gives every player a distinct slot', function () {
    foreach (range(1, 10) as $_) {
        queueUp(User::factory()->create(), 5);
    }
    app(QueueService::class)->formMatches();

    $match = MobaMatch::with('players')->first();
    expect($match)->not->toBeNull()
        ->and($match->players)->toHaveCount(10)
        ->and($match->players->where('team', 0))->toHaveCount(5)
        ->and($match->players->where('team', 1))->toHaveCount(5)
        ->and($match->players->pluck('slot')->unique())->toHaveCount(10);

    // Even slots Blue, odd slots Red — the convention the game server assigns seats by, so the
    // two halves agree without either having to know the other's rule.
    foreach ($match->players as $player) {
        expect($player->slot % 2)->toBe($player->team);
    }
});

it('balances a 2v2 by rating rather than by arrival order', function () {
    // Snake seating: the strongest and weakest go together. Alternating instead would stack the
    // top half of the run onto one team, which is the opposite of balancing.
    // Inside the opening search window, so the match forms without waiting — the widening rule
    // has its own test and does not need re-proving here.
    $ratings = [1260, 1240, 1160, 1140];
    foreach ($ratings as $mmr) {
        $user = User::factory()->create();
        MobaProfile::forUser($user)->update(['mmr' => $mmr]);
        queueUp($user, 2);
    }
    app(QueueService::class)->formMatches();

    $match = MobaMatch::with('players')->first();
    expect($match)->not->toBeNull();

    $mean = fn (int $team) => $match->players->where('team', $team)
        ->avg(fn ($p) => MobaProfile::forUser($p->user_id)->mmr);

    // Snake seating pairs the strongest with the weakest, so the two means should land on top
    // of each other. Alternating would give 1250 against 1150.
    expect(abs($mean(0) - $mean(1)))->toBeLessThan(30.0);
});

it('refuses to match players whose ratings are far apart until they have waited', function () {
    $strong = User::factory()->create();
    $weak = User::factory()->create();
    MobaProfile::forUser($strong)->update(['mmr' => 2500]);
    MobaProfile::forUser($weak)->update(['mmr' => 800]);

    queueUp($strong);
    queueUp($weak);
    app(QueueService::class)->formMatches();
    expect(MobaMatch::count())->toBe(0, 'a 1700-point gap matched immediately');

    // Wait long enough for the window to widen past the gap. A player who has waited would
    // rather have an uneven game than no game.
    $this->travel(5)->minutes();
    app(QueueService::class)->formMatches();

    expect(MobaMatch::count())->toBe(1, 'the search window never widened');
});

it('widens the window with waiting and then stops', function () {
    $queue = app(QueueService::class);
    expect($queue->windowFor(0))->toBeLessThan($queue->windowFor(30))
        ->and($queue->windowFor(30))->toBeLessThan($queue->windowFor(120))
        // Capped, or a player who walked away for an hour would match anyone at all.
        ->and($queue->windowFor(100_000))->toBe($queue->windowFor(200_000));
});

it('replaces a queue entry rather than refusing a second one', function () {
    // Pressing "find match" again with a different hero means you changed your mind.
    $user = User::factory()->create();
    queueUp($user, 1, 'ironclad');
    queueUp($user, 5, 'relay');

    expect(MobaQueueEntry::where('user_id', $user->id)->count())->toBe(1);
    $entry = MobaQueueEntry::where('user_id', $user->id)->first();
    expect($entry->hero)->toBe('relay')->and($entry->team_size)->toBe(5);
});

it('will not queue someone who is already in a match', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    queueUp($a);
    queueUp($b);
    app(QueueService::class)->formMatches();

    expect(fn () => queueUp($a))->toThrow(Illuminate\Validation\ValidationException::class);
});

it('refuses a hero that does not exist', function () {
    expect(fn () => queueUp(User::factory()->create(), 1, 'nosuchhero'))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

// ── Rating ──────────────────────────────────────────────────────────────────────────────────

it('moves rating toward the winner and away from the loser by the same amount', function () {
    $winner = User::factory()->create();
    $loser = User::factory()->create();
    queueUp($winner);
    queueUp($loser);
    app(QueueService::class)->formMatches();

    $match = MobaMatch::with('players')->first();
    $winningTeam = $match->players->firstWhere('user_id', $winner->id)->team;
    $match->update(['winning_team' => $winningTeam, 'status' => MobaMatch::STATUS_FINISHED]);
    app(RatingService::class)->apply($match->fresh('players'));

    $up = MobaProfile::forUser($winner);
    $down = MobaProfile::forUser($loser);

    expect($up->mmr)->toBeGreaterThan(MobaProfile::STARTING_MMR)
        ->and($down->mmr)->toBeLessThan(MobaProfile::STARTING_MMR)
        ->and($up->mmr - MobaProfile::STARTING_MMR)
        ->toBe(MobaProfile::STARTING_MMR - $down->mmr, 'evenly matched players moved unevenly');

    expect($up->games)->toBe(1)->and($up->wins)->toBe(1)
        ->and($down->games)->toBe(1)->and($down->wins)->toBe(0);
});

it('gives a favourite less for winning than an underdog', function () {
    // The whole point of Elo. Beating someone far below you should be worth almost nothing.
    $rate = function (int $favouriteMmr, bool $favouriteWins): int {
        MobaMatch::query()->delete();
        $a = User::factory()->create();
        $b = User::factory()->create();
        MobaProfile::forUser($a)->update(['mmr' => $favouriteMmr]);
        MobaProfile::forUser($b)->update(['mmr' => 1200]);
        queueUp($a);
        queueUp($b);
        // Force the pairing regardless of the rating gap.
        app(QueueService::class)->formMatches();
        test()->travel(10)->minutes();
        app(QueueService::class)->formMatches();

        $match = MobaMatch::with('players')->latest('id')->first();
        $seat = $match->players->firstWhere('user_id', $a->id);
        $match->update(['winning_team' => $favouriteWins ? $seat->team : 1 - $seat->team]);
        app(RatingService::class)->apply($match->fresh('players'));

        return (int) $match->players()->where('user_id', $a->id)->value('mmr_change');
    };

    expect($rate(2200, true))->toBeLessThan($rate(1200, true));
});

it('never lets a rating go negative', function () {
    $user = User::factory()->create();
    MobaProfile::forUser($user)->update(['mmr' => 5]);
    $other = User::factory()->create();
    queueUp($user);
    queueUp($other);
    $this->travel(10)->minutes();
    app(QueueService::class)->formMatches();

    $match = MobaMatch::with('players')->first();
    $seat = $match->players->firstWhere('user_id', $user->id);
    $match->update(['winning_team' => 1 - $seat->team]);
    app(RatingService::class)->apply($match->fresh('players'));

    expect(MobaProfile::forUser($user)->mmr)->toBeGreaterThanOrEqual(0);
});

// ── The API ─────────────────────────────────────────────────────────────────────────────────

it('serves the roster', function () {
    passportGet(User::factory()->create(), '/api/moba/catalogue')
        ->assertOk()
        ->assertJsonCount(count(Heroes::ids()), 'heroes')
        ->assertJsonPath('team_sizes', [1, 2, 3, 4, 5]);
});

it('reports a fresh player as unranked and unqueued', function () {
    passportGet(User::factory()->create(), '/api/moba/me')
        ->assertOk()
        ->assertJsonPath('mmr', MobaProfile::STARTING_MMR)
        ->assertJsonPath('queued', false)
        ->assertJsonPath('match', null);
});

it('hands a ticket to a player in a match and to nobody else', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $stranger = User::factory()->create();
    queueUp($a);
    queueUp($b);
    app(QueueService::class)->formMatches();
    $match = MobaMatch::first();

    $response = passportGet($a, "/api/moba/matches/{$match->id}")->assertOk();
    $ticket = $response->json('ticket');
    expect($ticket)->toBeString();

    $payload = MatchTicket::verify($ticket);
    expect($payload['u'])->toBe($a->id)->and($payload['m'])->toBe($match->id);

    passportGet($stranger, "/api/moba/matches/{$match->id}")->assertForbidden();
});

it('lets a player queue and leave through the API', function () {
    $user = User::factory()->create();
    $channel = Channel::factory()->create();

    Passport::actingAs($user);
    $this
        ->postJson('/api/moba/queue', ['team_size' => 1, 'hero' => 'jukebox', 'channel_id' => $channel->id])
        ->assertOk()
        ->assertJsonPath('queued', true)
        ->assertJsonPath('queued_size', 1);

    $this->deleteJson('/api/moba/queue')->assertOk()->assertJsonPath('queued', false);
});

it('requires authentication for every queue route', function () {
    $this->getJson('/api/moba/me')->assertUnauthorized();
    $this->postJson('/api/moba/queue', ['team_size' => 1, 'hero' => 'ironclad'])->assertUnauthorized();
});

// ── The result callback ─────────────────────────────────────────────────────────────────────

function reportResult(MobaMatch $match, array $body, ?string $secret = null): Illuminate\Testing\TestResponse
{
    $json = json_encode($body);
    $signature = hash_hmac('sha256', $json, $secret ?? config('app.key'));

    return test()->call(
        'POST',
        "/api/moba/matches/{$match->id}/result",
        [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_MOBA_SIGNATURE' => $signature],
        $json,
    );
}

function finishedBody(MobaMatch $match, int $winningTeam = 0): array
{
    return [
        'winning_team' => $winningTeam,
        'players' => $match->players->map(fn ($p) => [
            'slot' => $p->slot, 'kills' => 3, 'deaths' => 1, 'assists' => 2,
            'gold' => 5000, 'damage' => 12000,
        ])->values()->all(),
    ];
}

it('records a signed result and applies rating', function () {
    queueUp(User::factory()->create());
    queueUp(User::factory()->create());
    app(QueueService::class)->formMatches();
    $match = MobaMatch::with('players')->first();

    reportResult($match, finishedBody($match))->assertOk();

    $match->refresh();
    expect($match->status)->toBe(MobaMatch::STATUS_FINISHED)
        ->and($match->winning_team)->toBe(0)
        ->and($match->ended_at)->not->toBeNull();

    $winner = $match->players->where('team', 0)->first();
    expect((int) $match->players()->where('slot', $winner->slot)->value('kills'))->toBe(3);
    expect($match->players()->whereNotNull('mmr_change')->count())->toBe(2);
});

it('rejects a result that is not signed with the shared secret', function () {
    queueUp(User::factory()->create());
    queueUp(User::factory()->create());
    app(QueueService::class)->formMatches();
    $match = MobaMatch::with('players')->first();

    reportResult($match, finishedBody($match), 'not-the-secret')->assertUnauthorized();
    expect($match->fresh()->status)->not->toBe(MobaMatch::STATUS_FINISHED);
});

it('treats a repeated result as already done rather than applying it twice', function () {
    // A game server that reports, times out waiting for the response, and retries must not
    // double-apply rating.
    queueUp(User::factory()->create());
    queueUp(User::factory()->create());
    app(QueueService::class)->formMatches();
    $match = MobaMatch::with('players')->first();

    reportResult($match, finishedBody($match))->assertOk();
    $after = MobaProfile::forUser($match->players->first()->user_id)->mmr;

    reportResult($match, finishedBody($match))->assertOk();

    expect(MobaProfile::forUser($match->players->first()->user_id)->mmr)->toBe($after)
        ->and(MobaProfile::forUser($match->players->first()->user_id)->games)->toBe(1);
});

// ── The seam with the simulation ────────────────────────────────────────────────────────────

it('lists exactly the heroes the simulation implements', function () {
    // The one duplication in the project that is accepted rather than removed: the roster is
    // defined in Rust and mirrored here so the lobby can draw a grid without the game server
    // running. This walks the pair, so adding a hero on one side and not the other fails here
    // rather than as a pick the game server refuses at seat time.
    // Mounted read-only into the container for exactly this — see docker-compose.yml. It is
    // allowed to be absent (a bare checkout of the backend alone), but not silently: a test
    // that only ever skips is a test that proves nothing.
    $rust = '/game/moba/moba-sim/src/ability.rs';
    if (! file_exists($rust)) {
        $rust = base_path('../game/moba/moba-sim/src/ability.rs');
    }
    if (! file_exists($rust)) {
        test()->markTestSkipped('the simulation crate is not mounted; see docker-compose.yml');
    }

    $source = file_get_contents($rust);
    preg_match_all('/pub fn (\w+)\(\) -> Hero \{/', $source, $matches);
    $inRust = collect($matches[1])->reject(fn ($n) => $n === 'all')->sort()->values()->all();

    expect(collect(Heroes::ids())->sort()->values()->all())->toBe($inRust);
});

// ── The match lifecycle ─────────────────────────────────────────────────────────────────────

it('is not a one-way door: a match nobody plays is eventually abandoned', function () {
    // The trap this closes, found by playing it: a match row is created the moment a roster
    // forms and counts as a commitment, but nothing else ever cleared that status. Players who
    // never connected — or whose game server was not running — were permanently unable to queue
    // again, with no way out from the UI.
    $a = User::factory()->create();
    $b = User::factory()->create();
    queueUp($a);
    queueUp($b);
    app(QueueService::class)->formMatches();

    expect(fn () => queueUp($a))->toThrow(Illuminate\Validation\ValidationException::class);

    $this->travel(5)->minutes();
    app(QueueService::class)->abandonStale();

    expect(MobaMatch::first()->status)->toBe(MobaMatch::STATUS_ABANDONED);
    // And now they can play again.
    queueUp($a);
    expect(MobaQueueEntry::where('user_id', $a->id)->exists())->toBeTrue();
});

it('gives a live match hours rather than minutes before writing it off', function () {
    // Two different failures. A match nobody started is abandoned quickly — people are staring
    // at a stuck lobby. One that *did* start took a server crash to get here, so it is given
    // long enough that a genuinely long game is never cut short.
    queueUp(User::factory()->create());
    queueUp(User::factory()->create());
    app(QueueService::class)->formMatches();
    $match = MobaMatch::first();
    app(QueueService::class)->markLive($match);

    $this->travel(30)->minutes();
    app(QueueService::class)->abandonStale();
    expect($match->fresh()->status)->toBe(MobaMatch::STATUS_LIVE, 'a half-hour game was killed');

    $this->travel(3)->hours();
    app(QueueService::class)->abandonStale();
    expect($match->fresh()->status)->toBe(MobaMatch::STATUS_ABANDONED);
});

it('marks a match live when someone takes a ticket for it', function () {
    // The closest thing the API has to knowing a match started: the game server never reports
    // one, and adding a third crossing between the two halves for a single timestamp is not
    // worth it.
    $a = User::factory()->create();
    queueUp($a);
    queueUp(User::factory()->create());
    app(QueueService::class)->formMatches();
    $match = MobaMatch::first();
    expect($match->status)->toBe(MobaMatch::STATUS_QUEUED);

    passportGet($a, "/api/moba/matches/{$match->id}")->assertOk();

    expect($match->fresh()->status)->toBe(MobaMatch::STATUS_LIVE)
        ->and($match->fresh()->started_at)->not->toBeNull();
});

it('lets a player leave a match, which ends it for everyone', function () {
    // A MOBA cannot be played a player short, so one person leaving ends it — kinder than
    // leaving four people in a game they can neither win nor leave.
    $a = User::factory()->create();
    $b = User::factory()->create();
    queueUp($a);
    queueUp($b);
    app(QueueService::class)->formMatches();
    $match = MobaMatch::first();

    Passport::actingAs($a);
    $this->postJson("/api/moba/matches/{$match->id}/leave")->assertOk();

    expect($match->fresh()->status)->toBe(MobaMatch::STATUS_ABANDONED);

    // Both are free again, not just the one who left.
    queueUp($a);
    queueUp($b);
    expect(MobaQueueEntry::count())->toBe(2);
});

it('will not let a stranger end someone elses match', function () {
    queueUp(User::factory()->create());
    queueUp(User::factory()->create());
    app(QueueService::class)->formMatches();
    $match = MobaMatch::first();

    Passport::actingAs(User::factory()->create());
    $this->postJson("/api/moba/matches/{$match->id}/leave")->assertForbidden();

    expect($match->fresh()->status)->not->toBe(MobaMatch::STATUS_ABANDONED);
});

it('will not let a leave rewrite a finished match', function () {
    // The result is the record. A stray leave arriving after the game server reported must not
    // be able to overwrite it.
    $a = User::factory()->create();
    queueUp($a);
    queueUp(User::factory()->create());
    app(QueueService::class)->formMatches();
    $match = MobaMatch::with('players')->first();
    reportResult($match, finishedBody($match))->assertOk();

    Passport::actingAs($a);
    $this->postJson("/api/moba/matches/{$match->id}/leave")->assertOk();

    expect($match->fresh()->status)->toBe(MobaMatch::STATUS_FINISHED);
});
