<?php

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Server;
use App\Models\User;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

/**
 * The admin panel: who can reach it, and what it does once you're in.
 *
 * The guard tests are the important half. Everything behind /api/admin bypasses the
 * ownership checks the rest of the app runs on, so "an ordinary account gets nothing" is
 * the property that has to hold for every route, not a nice-to-have on one of them.
 */
function superAdmin(): User
{
    return User::factory()->create(['role' => User::SUPER_ADMIN]);
}

it('hides the panel from ordinary accounts', function (string $method, string $uri) {
    Passport::actingAs(User::factory()->create());

    $this->json($method, $uri)->assertNotFound();
})->with([
    ['get', '/api/admin/overview'],
    ['get', '/api/admin/users'],
    ['get', '/api/admin/servers'],
    ['get', '/api/admin/conversations'],
    ['get', '/api/admin/messages'],
]);

it('hides the panel from a bot account even with the role set', function () {
    // Belt and braces: a bot can't hold a role (updateRole refuses), so this is about the
    // door staying shut if one ever gets one anyway.
    Passport::actingAs(User::factory()->create(['is_bot' => true, 'role' => User::SUPER_ADMIN]));

    $this->getJson('/api/admin/users')->assertNotFound();
});

it('lets a super admin list users with their standing', function () {
    $admin = superAdmin();
    $someone = User::factory()->create(['name' => 'Wendy']);
    Server::factory()->create(['owner_id' => $someone->id]);

    Passport::actingAs($admin);
    $response = $this->getJson('/api/admin/users?q=wendy')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($someone->id)
        ->and($response->json('data.0.banned'))->toBeFalse()
        ->and($response->json('data.0.owned_servers_count'))->toBe(1);
});

it('filters the user list down to the blocked ones', function () {
    $admin = superAdmin();
    User::factory()->create();
    $blocked = User::factory()->create(['banned_at' => now(), 'ban_reason' => 'Spam']);

    Passport::actingAs($admin);
    $response = $this->getJson('/api/admin/users?filter=banned')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($blocked->id);
});

it('edits a user', function () {
    $admin = superAdmin();
    $user = User::factory()->create(['name' => 'Old']);

    Passport::actingAs($admin);
    $this->patchJson("/api/admin/users/{$user->id}", ['name' => 'New'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New');

    expect($user->fresh()->name)->toBe('New');
});

it('blocks a user with a reason and tells them why at the login screen', function () {
    $admin = superAdmin();
    $user = User::factory()->create(['password' => bcrypt('secret-password')]);

    Passport::actingAs($admin);
    $this->postJson("/api/admin/users/{$user->id}/ban", ['reason' => 'Repeated harassment.'])
        ->assertOk()
        ->assertJsonPath('data.banned', true)
        ->assertJsonPath('data.ban_reason', 'Repeated harassment.');

    expect($user->fresh()->isBanned())->toBeTrue();

    // The whole point of the custom message: it's what they read when they try to sign in.
    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-password'])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'Repeated harassment.');
});

it('insists on a reason when blocking', function () {
    Passport::actingAs(superAdmin());
    $user = User::factory()->create();

    $this->postJson("/api/admin/users/{$user->id}/ban", ['reason' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');
});

it('locks out a token the blocked user was already holding', function () {
    $admin = superAdmin();
    $user = User::factory()->create();

    Passport::actingAs($admin);
    $this->postJson("/api/admin/users/{$user->id}/ban", ['reason' => 'Spamming invites.'])->assertOk();

    // A ban that only bit at the next sign-in would never bite somebody who is signed in.
    Passport::actingAs($user->fresh());
    $this->getJson('/api/auth/me')
        ->assertStatus(403)
        ->assertJsonPath('ban_reason', 'Spamming invites.');
});

it('lifts a block', function () {
    $admin = superAdmin();
    $user = User::factory()->create(['banned_at' => now(), 'ban_reason' => 'A misunderstanding']);

    Passport::actingAs($admin);
    $this->deleteJson("/api/admin/users/{$user->id}/ban")
        ->assertOk()
        ->assertJsonPath('data.banned', false)
        ->assertJsonPath('data.ban_reason', null);

    expect($user->fresh()->isBanned())->toBeFalse();
});

it('refuses to let an admin block or delete themselves', function () {
    $admin = superAdmin();

    Passport::actingAs($admin);
    $this->postJson("/api/admin/users/{$admin->id}/ban", ['reason' => 'Oops.'])->assertStatus(422);
    $this->deleteJson("/api/admin/users/{$admin->id}")->assertStatus(422);
    $this->putJson("/api/admin/users/{$admin->id}/role", ['role' => null])->assertStatus(422);

    expect($admin->fresh()->isBanned())->toBeFalse()
        ->and($admin->fresh()->isSuperAdmin())->toBeTrue();
});

it('refuses to block another super admin before they are demoted', function () {
    Passport::actingAs(superAdmin());
    $peer = superAdmin();

    $this->postJson("/api/admin/users/{$peer->id}/ban", ['reason' => 'Nope.'])->assertStatus(422);
    $this->deleteJson("/api/admin/users/{$peer->id}")->assertStatus(422);
});

it('grants and revokes the super admin role', function () {
    Passport::actingAs(superAdmin());
    $user = User::factory()->create();

    $this->putJson("/api/admin/users/{$user->id}/role", ['role' => User::SUPER_ADMIN])
        ->assertOk()
        ->assertJsonPath('data.role', User::SUPER_ADMIN);
    expect($user->fresh()->isSuperAdmin())->toBeTrue();

    $this->putJson("/api/admin/users/{$user->id}/role", ['role' => null])
        ->assertOk()
        ->assertJsonPath('data.role', null);
    expect($user->fresh()->isSuperAdmin())->toBeFalse();
});

it('rejects a role that does not exist', function () {
    Passport::actingAs(superAdmin());
    $user = User::factory()->create();

    $this->putJson("/api/admin/users/{$user->id}/role", ['role' => 'god'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role');
});

it('deletes a user and the servers they owned', function () {
    Passport::actingAs(superAdmin());
    $user = User::factory()->create();
    $server = Server::factory()->create(['owner_id' => $user->id]);

    $this->deleteJson("/api/admin/users/{$user->id}")->assertNoContent();

    expect(User::find($user->id))->toBeNull()
        ->and(Server::find($server->id))->toBeNull();
});

it('lists servers with their owner and channels', function () {
    $admin = superAdmin();
    $owner = User::factory()->create();
    $server = Server::factory()->create(['owner_id' => $owner->id, 'name' => 'Book Club']);
    Channel::factory()->create(['server_id' => $server->id, 'name' => 'book-talk']);

    Passport::actingAs($admin);
    $response = $this->getJson('/api/admin/servers')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Book Club')
        ->assertJsonPath('data.0.owner.id', $owner->id);

    // Not an exact count: a new server comes with a channel of its own, so the assertion
    // that means something is "the one we added is in there".
    expect($response->json('data.0.channels_count'))->toBeGreaterThanOrEqual(1);

    $detail = $this->getJson("/api/admin/servers/{$server->id}")->assertOk();
    expect(collect($detail->json('data.channels'))->pluck('name'))->toContain('book-talk');
});

it('renames a server and hands it to a new owner', function () {
    Passport::actingAs(superAdmin());
    $server = Server::factory()->create(['name' => 'Old Name']);
    $rescuer = User::factory()->create();

    $this->patchJson("/api/admin/servers/{$server->id}", [
        'name' => 'New Name',
        'owner_id' => $rescuer->id,
    ])->assertOk()->assertJsonPath('data.owner.id', $rescuer->id);

    expect($server->fresh()->name)->toBe('New Name');
});

it('deletes a server and its channels', function () {
    Passport::actingAs(superAdmin());
    $server = Server::factory()->create();
    $channel = Channel::factory()->create(['server_id' => $server->id]);

    $this->deleteJson("/api/admin/servers/{$server->id}")->assertNoContent();

    expect(Server::find($server->id))->toBeNull()
        ->and(Channel::find($channel->id))->toBeNull();
});

it('renames and deletes a channel', function () {
    Passport::actingAs(superAdmin());
    $channel = Channel::factory()->create(['name' => 'random']);

    $this->patchJson("/api/admin/channels/{$channel->id}", ['name' => 'off-topic'])
        ->assertOk()
        ->assertJsonPath('data.name', 'off-topic');

    $this->deleteJson("/api/admin/channels/{$channel->id}")->assertNoContent();
    expect(Channel::find($channel->id))->toBeNull();
});

it("refuses to delete a chat's channel out from under it", function () {
    Passport::actingAs(superAdmin());
    $conversation = Conversation::factory()->create(['type' => 'dm']);
    $channel = Channel::factory()->create(['server_id' => null, 'conversation_id' => $conversation->id]);

    $this->deleteJson("/api/admin/channels/{$channel->id}")->assertStatus(422);

    expect(Channel::find($channel->id))->not->toBeNull();
});

it('lists DMs and groups, and filters them by member', function () {
    $admin = superAdmin();
    [$a, $b] = [User::factory()->create(), User::factory()->create()];

    $dm = Conversation::factory()->create(['type' => 'dm']);
    $dm->members()->attach([$a->id, $b->id]);
    Channel::factory()->create(['server_id' => null, 'conversation_id' => $dm->id]);

    $group = Conversation::factory()->create(['type' => 'group', 'name' => 'Trip']);
    $group->members()->attach([$b->id]);

    Passport::actingAs($admin);

    $this->getJson('/api/admin/conversations?type=group')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Trip');

    $this->getJson("/api/admin/conversations?user_id={$a->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $dm->id);
});

it('deletes a chat and its messages', function () {
    Passport::actingAs(superAdmin());
    $conversation = Conversation::factory()->create(['type' => 'dm']);
    $channel = Channel::factory()->create(['server_id' => null, 'conversation_id' => $conversation->id]);
    $message = Message::factory()->create(['channel_id' => $channel->id]);

    $this->deleteJson("/api/admin/conversations/{$conversation->id}")->assertNoContent();

    expect(Conversation::find($conversation->id))->toBeNull()
        ->and(Message::find($message->id))->toBeNull();
});

it('audits messages by author, room and text', function () {
    $admin = superAdmin();
    $author = User::factory()->create();
    $channel = Channel::factory()->create();
    Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $author->id, 'body' => 'the quiet part out loud']);
    Message::factory()->create(['channel_id' => $channel->id, 'body' => 'something else entirely']);

    Passport::actingAs($admin);

    $this->getJson("/api/admin/messages?user_id={$author->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.body', 'the quiet part out loud')
        ->assertJsonPath('data.0.author.id', $author->id);

    $this->getJson('/api/admin/messages?q=quiet+part')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson("/api/admin/messages?channel_id={$channel->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('never hands an auditor the contents of an encrypted message', function () {
    $admin = superAdmin();
    $channel = Channel::factory()->create(['encrypted' => true]);
    Message::factory()->create(['channel_id' => $channel->id, 'encrypted' => true, 'body' => 'AAAAciphertextAAAA']);

    Passport::actingAs($admin);
    $response = $this->getJson("/api/admin/messages?channel_id={$channel->id}")->assertOk();

    // Present, flagged, unreadable — the server has no key, and saying so beats pretending.
    expect($response->json('data.0.encrypted'))->toBeTrue()
        ->and($response->json('data.0.body'))->toBeNull();
});

it('deletes a message from the audit view', function () {
    Passport::actingAs(superAdmin());
    $message = Message::factory()->create();

    $this->deleteJson("/api/admin/messages/{$message->id}")->assertNoContent();

    expect(Message::find($message->id))->toBeNull();
});

it('summarises the instance on the overview', function () {
    // The instance already has one super admin before this test runs: the migration creates
    // it, so there is never a database with nobody able to get in. Counted as a baseline
    // rather than assumed away.
    $before = User::count();

    $admin = superAdmin();
    User::factory()->count(2)->create();
    User::factory()->create(['banned_at' => now(), 'ban_reason' => 'Spam']);
    Server::factory()->create();

    Passport::actingAs($admin);
    $response = $this->getJson('/api/admin/overview')->assertOk();

    expect($response->json('counts.users'))->toBe($before + 5)
        ->and($response->json('counts.banned'))->toBe(1)
        ->and($response->json('counts.admins'))->toBe(2)
        ->and($response->json('counts.servers'))->toBe(1)
        ->and($response->json('banned_users'))->toHaveCount(1);
});

/** Issuing a token needs a personal access client in the refreshed test database. */
function withTokenIssuing(): void
{
    app(ClientRepository::class)->createPersonalAccessGrantClient('Testing');
}

it('includes your role in the sign-in response, not just on /me', function () {
    withTokenIssuing();

    // The login response is the only place a user is serialised on a request that isn't
    // authenticated yet, so it's the one place the "your own fields" merge can silently drop
    // out. When it does, an admin signs in and lands in the ordinary app with no way through
    // to the panel until something forces a reload.
    User::factory()->create([
        'email' => 'boss@example.com',
        'password' => bcrypt('secret-password'),
        'role' => User::SUPER_ADMIN,
    ]);

    $this->postJson('/api/auth/login', ['email' => 'boss@example.com', 'password' => 'secret-password'])
        ->assertOk()
        ->assertJsonPath('user.role', User::SUPER_ADMIN);
});

it('reports a null role at sign-in for an ordinary account', function () {
    withTokenIssuing();
    User::factory()->create(['email' => 'nobody@example.com', 'password' => bcrypt('secret-password')]);

    $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'secret-password'])
        ->assertOk()
        ->assertJsonPath('user.role', null);
});

it('tells you your own role and nobody else theirs', function () {
    $admin = superAdmin();

    Passport::actingAs($admin);
    $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.role', User::SUPER_ADMIN);

    // Somebody else reading the same account gets no role field at all.
    Passport::actingAs(User::factory()->create());
    $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.role', null);
});
