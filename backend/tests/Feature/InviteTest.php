<?php

use App\Models\Channel;
use App\Models\Message;
use App\Models\Server;
use App\Models\ServerJoinRequest;
use App\Models\User;
use Laravel\Passport\Passport;

it('previews an invite for a user who is not a member', function () {
    [, $server] = ownerWithServer();
    $outsider = User::factory()->create();

    Passport::actingAs($outsider);

    $this->getJson("/api/invites/{$server->invite_code}")
        ->assertOk()
        ->assertJsonPath('data.server.name', $server->name)
        ->assertJsonPath('data.server.members_count', 1)
        ->assertJsonPath('data.status', 'none');
});

it('404s on an unknown invite code', function () {
    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/invites/does-not-exist')->assertNotFound();
});

it('requires authentication to view an invite', function () {
    [, $server] = ownerWithServer();

    $this->getJson("/api/invites/{$server->invite_code}")->assertUnauthorized();
});

it('reports member status for someone already in the server', function () {
    [$owner, $server] = ownerWithServer();

    Passport::actingAs($owner);

    $this->getJson("/api/invites/{$server->invite_code}")
        ->assertOk()
        ->assertJsonPath('data.status', 'member');
});

it('creates a pending join request from an invite, and is idempotent', function () {
    [, $server] = ownerWithServer();
    $outsider = User::factory()->create();

    Passport::actingAs($outsider);

    $this->postJson("/api/invites/{$server->invite_code}/join")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    // Opening the invite again does not stack duplicate requests.
    $this->postJson("/api/invites/{$server->invite_code}/join")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    expect(ServerJoinRequest::where('server_id', $server->id)->count())->toBe(1)
        ->and($server->fresh()->hasMember($outsider))->toBeFalse(); // not a member until approved
});

it('does not create a request when the user is already a member', function () {
    [$owner, $server] = ownerWithServer();

    Passport::actingAs($owner);

    $this->postJson("/api/invites/{$server->invite_code}/join")
        ->assertOk()
        ->assertJsonPath('data.status', 'member');

    expect(ServerJoinRequest::count())->toBe(0);
});

// ---- reviewing requests ----

it('lists pending requests to members and forbids outsiders', function () {
    [$owner, $server] = ownerWithServer();
    $applicant = User::factory()->create();
    ServerJoinRequest::factory()->create(['server_id' => $server->id, 'user_id' => $applicant->id]);

    Passport::actingAs($owner);
    $this->getJson("/api/servers/{$server->id}/join-requests")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user.id', $applicant->id);

    Passport::actingAs(User::factory()->create());
    $this->getJson("/api/servers/{$server->id}/join-requests")->assertForbidden();
});

it('exposes the pending request count on the server', function () {
    [$owner, $server] = ownerWithServer();
    ServerJoinRequest::factory()->count(2)->create(['server_id' => $server->id]);

    Passport::actingAs($owner);

    $this->getJson("/api/servers/{$server->id}")
        ->assertOk()
        ->assertJsonPath('data.pending_requests_count', 2)
        ->assertJsonPath('data.invite_code', $server->invite_code);
});

// ---- approve ----

it('bulk approves requests, admits the users, and announces them in the first text channel', function () {
    [$owner, $server, $channel] = ownerWithChannel();

    // A second, later text channel must NOT be used.
    Channel::factory()->create(['server_id' => $server->id, 'position' => 5]);

    $a = User::factory()->create(['name' => 'Ada']);
    $b = User::factory()->create(['name' => 'Grace']);
    $ra = ServerJoinRequest::factory()->create(['server_id' => $server->id, 'user_id' => $a->id]);
    $rb = ServerJoinRequest::factory()->create(['server_id' => $server->id, 'user_id' => $b->id]);

    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/join-requests/approve", [
        'request_ids' => [$ra->id, $rb->id],
    ])->assertOk()->assertJsonPath('approved', 2);

    $server->refresh();

    expect($server->hasMember($a))->toBeTrue()
        ->and($server->hasMember($b))->toBeTrue()
        ->and(ServerJoinRequest::count())->toBe(0);

    // One system message per admitted user, in the FIRST text channel.
    $system = Message::where('type', 'system')->get();

    expect($system)->toHaveCount(2)
        ->and($system->pluck('channel_id')->unique()->all())->toBe([$channel->id])
        ->and($system->pluck('body')->all())->toBe([
            'Ada joined the server',
            'Grace joined the server',
        ]);
});

it('posts no system message when the server has no text channel', function () {
    [$owner, $server] = ownerWithServer();
    Channel::factory()->voice()->create(['server_id' => $server->id]); // voice only

    $applicant = User::factory()->create();
    $request = ServerJoinRequest::factory()->create(['server_id' => $server->id, 'user_id' => $applicant->id]);

    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/join-requests/approve", [
        'request_ids' => [$request->id],
    ])->assertOk()->assertJsonPath('approved', 1);

    expect($server->fresh()->hasMember($applicant))->toBeTrue()
        ->and(Message::count())->toBe(0); // joined, but nothing announced
});

// ---- decline ----

it('bulk declines requests by simply deleting them', function () {
    [$owner, $server, $channel] = ownerWithChannel();

    $applicant = User::factory()->create();
    $request = ServerJoinRequest::factory()->create(['server_id' => $server->id, 'user_id' => $applicant->id]);

    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/join-requests/decline", [
        'request_ids' => [$request->id],
    ])->assertOk()->assertJsonPath('declined', 1);

    expect(ServerJoinRequest::count())->toBe(0)
        ->and($server->fresh()->hasMember($applicant))->toBeFalse()
        ->and(Message::count())->toBe(0); // declining announces nothing
});

it('forbids non-members from approving or declining', function () {
    [, $server] = ownerWithServer();
    $request = ServerJoinRequest::factory()->create(['server_id' => $server->id]);

    Passport::actingAs(User::factory()->create());

    $this->postJson("/api/servers/{$server->id}/join-requests/approve", ['request_ids' => [$request->id]])
        ->assertForbidden();
    $this->postJson("/api/servers/{$server->id}/join-requests/decline", ['request_ids' => [$request->id]])
        ->assertForbidden();

    expect(ServerJoinRequest::count())->toBe(1);
});

it('validates that request_ids is provided', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/join-requests/approve", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('request_ids');
});

it('cannot approve a request belonging to a different server', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $otherServer = Server::factory()->create();
    $foreign = ServerJoinRequest::factory()->create(['server_id' => $otherServer->id]);

    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/join-requests/approve", ['request_ids' => [$foreign->id]])
        ->assertOk()
        ->assertJsonPath('approved', 0); // scoped to this server - nothing happens

    expect(ServerJoinRequest::find($foreign->id))->not->toBeNull();
});
