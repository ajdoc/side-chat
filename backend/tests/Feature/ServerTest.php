<?php

use App\Models\Server;
use App\Models\User;
use Laravel\Passport\Passport;

it('lists only the servers the user belongs to, paginated', function () {
    [$user, $server] = ownerWithServer();
    Server::factory()->create(); // someone else's server

    Passport::actingAs($user);

    $this->getJson('/api/servers')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $server->id)
        ->assertJsonPath('meta.per_page', 200);
});

it('creates a server and makes the creator the owner and first member', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $response = $this->postJson('/api/servers', ['name' => 'Design Team'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Design Team')
        ->assertJsonPath('data.is_owner', true);

    $server = Server::find($response->json('data.id'));

    expect($server->owner_id)->toBe($user->id)
        ->and($server->hasMember($user))->toBeTrue();
});

it('validates the server name', function () {
    Passport::actingAs(User::factory()->create());

    $this->postJson('/api/servers', ['name' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('lets a member view a server but forbids non-members', function () {
    [$owner, $server] = ownerWithServer();

    Passport::actingAs($owner);
    $this->getJson("/api/servers/{$server->id}")->assertOk();

    Passport::actingAs(User::factory()->create());
    $this->getJson("/api/servers/{$server->id}")->assertForbidden();
});
