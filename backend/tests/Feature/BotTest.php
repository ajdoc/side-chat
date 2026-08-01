<?php

use App\Models\Bot;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Server;
use App\Models\User;
use Laravel\Passport\Passport;

/*
 * Bots: registering one, what its token opens, and what it doesn't.
 *
 * The registration half is ordinary owner-only CRUD and gets light coverage. The weight is
 * on the token, because that's the part with teeth: it's long-lived, it lives in somebody
 * else's CI config, and it authenticates as a member of a server. So the tests that matter
 * are the boundaries — a token reaching a second server, a retired token still working, a
 * bot posting into a private channel it was never let into.
 */

/**
 * A bot registered on a server, and the token to drive it.
 *
 * Built through the factory rather than the endpoint so a test about *using* a bot doesn't
 * fail when something about creating one changes.
 *
 * @return array{0: Bot, 1: string}
 */
function botOn(Server $server): array
{
    $token = Bot::generateToken();
    $user = User::factory()->bot()->create();
    $server->members()->attach($user->id, ['role' => 'member']);

    $bot = Bot::factory()->withToken($token)->create([
        'user_id' => $user->id,
        'server_id' => $server->id,
    ]);

    return [$bot, $token];
}

/** @return array<string, string> */
function botAuth(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

it('lets a server owner register a bot and shows the token exactly once', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $response = $this->postJson("/api/servers/{$server->id}/bots", ['name' => 'Deploy Bot'])
        ->assertCreated()
        ->assertJsonPath('data.user.name', 'Deploy Bot')
        ->assertJsonPath('data.user.is_bot', true);

    $token = $response->json('token');
    expect($token)->toStartWith('sc_bot_');

    // Listing it again gives back everything but the credential.
    $this->getJson("/api/servers/{$server->id}/bots")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissingPath('data.0.token')
        ->assertJsonMissingPath('data.0.token_hash');
});

it('puts the bot on the server roster so it can be seen and mentioned', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/bots", ['name' => 'Deploy Bot'])->assertCreated();

    $bot = Bot::first();
    expect($server->fresh()->hasMember($bot->user))->toBeTrue();
});

it('refuses to let an admin register a bot', function () {
    [, $admin, $server] = twoMembers();
    $server->members()->updateExistingPivot($admin->id, ['role' => 'admin']);
    Passport::actingAs($admin);

    $this->postJson("/api/servers/{$server->id}/bots", ['name' => 'Sneaky'])->assertForbidden();
});

it('lets a bot post a message that reads as an ordinary message', function () {
    [, $server, $channel] = ownerWithChannel();
    [$bot, $token] = botOn($server);

    $this->withHeaders(botAuth($token))
        ->postJson("/api/bot/channels/{$channel->id}/messages", ['body' => 'build passed'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'build passed')
        ->assertJsonPath('data.user.id', $bot->user_id)
        ->assertJsonPath('data.user.is_bot', true);

    expect(Message::where('channel_id', $channel->id)->count())->toBe(1);
});

it('tells a bot who and where it is', function () {
    [, $server, $channel] = ownerWithChannel();
    [$bot, $token] = botOn($server);

    $this->withHeaders(botAuth($token))
        ->getJson('/api/bot/me')
        ->assertOk()
        ->assertJsonPath('data.id', $bot->id)
        ->assertJsonPath('data.server.id', $server->id)
        ->assertJsonPath('data.channels.0.id', $channel->id);
});

it('refuses a missing, malformed or unknown token', function (?string $token) {
    [, , $channel] = ownerWithChannel();

    $headers = $token === null ? [] : botAuth($token);

    $this->withHeaders($headers)
        ->postJson("/api/bot/channels/{$channel->id}/messages", ['body' => 'hello'])
        ->assertUnauthorized();
})->with([null, '', 'sc_bot_nonsense']);

it('stops a token from reaching a channel in another server', function () {
    [, $server] = ownerWithServer();
    [, $token] = botOn($server);

    // A second server the bot's *account* happens to be a member of — which is the case a
    // membership check alone would wave through.
    [, $elsewhere, $farChannel] = ownerWithChannel();
    $elsewhere->members()->attach(Bot::first()->user_id, ['role' => 'member']);

    $this->withHeaders(botAuth($token))
        ->postJson("/api/bot/channels/{$farChannel->id}/messages", ['body' => 'trespassing'])
        ->assertForbidden();
});

it('keeps a bot out of a private channel it has not been added to', function () {
    [, $server] = ownerWithServer();
    $private = Channel::factory()->create(['server_id' => $server->id, 'is_private' => true]);
    [, $token] = botOn($server);

    $this->withHeaders(botAuth($token))
        ->postJson("/api/bot/channels/{$private->id}/messages", ['body' => 'peeking'])
        ->assertForbidden();
});

it('invalidates the old token when the owner rotates it', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    [$bot, $old] = botOn($server);
    Passport::actingAs($owner);

    $new = $this->postJson("/api/servers/{$server->id}/bots/{$bot->id}/token")
        ->assertOk()
        ->json('data.token');

    expect($new)->not->toBe($old);

    $this->withHeaders(botAuth($old))
        ->postJson("/api/bot/channels/{$channel->id}/messages", ['body' => 'still here?'])
        ->assertUnauthorized();

    $this->withHeaders(botAuth($new))
        ->postJson("/api/bot/channels/{$channel->id}/messages", ['body' => 'rotated'])
        ->assertCreated();
});

it('retires a bot without erasing what it said', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    [$bot, $token] = botOn($server);

    $this->withHeaders(botAuth($token))
        ->postJson("/api/bot/channels/{$channel->id}/messages", ['body' => 'build passed'])
        ->assertCreated();

    Passport::actingAs($owner);
    $this->deleteJson("/api/servers/{$server->id}/bots/{$bot->id}")->assertNoContent();

    // The history stands, the credential is dead, and it's off the roster.
    expect(Message::where('channel_id', $channel->id)->count())->toBe(1)
        ->and(Bot::count())->toBe(0)
        ->and($server->fresh()->hasMember($bot->user))->toBeFalse();

    $this->withHeaders(botAuth($token))
        ->postJson("/api/bot/channels/{$channel->id}/messages", ['body' => 'zombie'])
        ->assertUnauthorized();
});

it('refuses to let one server owner touch another server\'s bot', function () {
    [, $mine] = ownerWithServer();
    [$bot] = botOn($mine);

    [$outsider, $theirs] = ownerWithServer();
    Passport::actingAs($outsider);

    $this->deleteJson("/api/servers/{$theirs->id}/bots/{$bot->id}")->assertNotFound();
    expect(Bot::count())->toBe(1);
});

it('renames a bot through the account it posts as', function () {
    [$owner, $server] = ownerWithServer();
    [$bot] = botOn($server);
    Passport::actingAs($owner);

    $this->patchJson("/api/servers/{$server->id}/bots/{$bot->id}", ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.user.name', 'Renamed');

    expect($bot->user->fresh()->name)->toBe('Renamed');
});
