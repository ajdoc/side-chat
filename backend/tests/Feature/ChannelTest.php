<?php

use App\Models\Channel;
use App\Models\User;
use Laravel\Passport\Passport;

it('lists a server channels paginated', function () {
    [$user, $server] = ownerWithServer();
    Channel::factory()->count(3)->create(['server_id' => $server->id]);

    Passport::actingAs($user);

    $this->getJson("/api/servers/{$server->id}/channels")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.per_page', 200);
});

it('creates text and voice channels', function () {
    [$user, $server] = ownerWithServer();
    Passport::actingAs($user);

    $this->postJson("/api/servers/{$server->id}/channels", ['name' => 'general', 'type' => 'text'])
        ->assertCreated()
        ->assertJsonPath('data.type', 'text')
        ->assertJsonPath('data.name', 'general');

    $this->postJson("/api/servers/{$server->id}/channels", ['name' => 'Lounge', 'type' => 'voice'])
        ->assertCreated()
        ->assertJsonPath('data.type', 'voice');
});

it('rejects an unknown channel type', function () {
    [$user, $server] = ownerWithServer();
    Passport::actingAs($user);

    $this->postJson("/api/servers/{$server->id}/channels", ['name' => 'x', 'type' => 'video'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');
});

it('forbids non-members from listing or creating channels', function () {
    [, $server] = ownerWithServer();
    Passport::actingAs(User::factory()->create());

    $this->getJson("/api/servers/{$server->id}/channels")->assertForbidden();
    $this->postJson("/api/servers/{$server->id}/channels", ['name' => 'x', 'type' => 'text'])
        ->assertForbidden();
});
