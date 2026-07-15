<?php

use App\Models\Channel;
use App\Models\User;
use Laravel\Passport\Passport;

it('renames a server', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $this->patchJson("/api/servers/{$server->id}", ['name' => 'Renamed Server'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed Server');

    expect($server->fresh()->name)->toBe('Renamed Server');
});

it('renames a channel without changing its type', function () {
    [$owner, $server] = ownerWithServer();
    $channel = Channel::factory()->create(['server_id' => $server->id, 'type' => 'voice']);
    Passport::actingAs($owner);

    // The type isn't editable — a voice channel that became a text channel would strand
    // whoever was in the call.
    $this->patchJson("/api/channels/{$channel->id}", ['name' => 'The Lounge', 'type' => 'text'])
        ->assertOk()
        ->assertJsonPath('data.name', 'The Lounge')
        ->assertJsonPath('data.type', 'voice');

    expect($channel->fresh()->type)->toBe('voice');
});

it('validates the new name', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->patchJson("/api/servers/{$server->id}", ['name' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->patchJson("/api/channels/{$channel->id}", ['name' => str_repeat('x', 101)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('lets only the owner rename a server or channel', function () {
    [, $server, $channel] = ownerWithChannel();

    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);
    Passport::actingAs($member);

    $this->patchJson("/api/servers/{$server->id}", ['name' => 'Hostile Takeover'])->assertForbidden();
    $this->patchJson("/api/channels/{$channel->id}", ['name' => 'hijacked'])->assertForbidden();

    Passport::actingAs(User::factory()->create());
    $this->patchJson("/api/servers/{$server->id}", ['name' => 'Nope'])->assertForbidden();
    $this->patchJson("/api/channels/{$channel->id}", ['name' => 'nope'])->assertForbidden();

    expect($server->fresh()->name)->not->toBe('Hostile Takeover')
        ->and($channel->fresh()->name)->not->toBe('hijacked');
});
