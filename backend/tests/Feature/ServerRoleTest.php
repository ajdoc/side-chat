<?php

use App\Models\Channel;
use App\Models\Server;
use App\Models\User;
use Laravel\Passport\Passport;

/*
 * Server roles: what an admin is, who may create one, and what having one buys.
 *
 * The interesting boundary isn't "can an admin do X" for every X — that's one shared base
 * class (ServerStaffRequest) and testing it thirty times tests the same line thirty times.
 * It's the *edges*: that a plain member still can't, that an admin can do the delegated
 * things, and that the two powers reserved for the owner really are reserved.
 */

it('starts every member off as a plain member', function () {
    [, $other, $server] = twoMembers();

    expect($server->isStaff($other))->toBeFalse()
        ->and($server->roleFor($other))->toBe('member');
});

it('counts the owner as staff without any pivot role', function () {
    [$owner, $server] = ownerWithServer();

    expect($server->isStaff($owner))->toBeTrue()
        ->and($server->roleFor($owner))->toBe('owner');
});

it('lets the owner promote a member to admin', function () {
    [$owner, $other, $server] = twoMembers();
    Passport::actingAs($owner);

    $this->patchJson("/api/servers/{$server->id}/members/{$other->id}/role", ['role' => 'admin'])
        ->assertOk()
        ->assertJsonPath('data.role', 'admin');

    expect($server->fresh()->isStaff($other))->toBeTrue();
});

it('lets the owner demote an admin back to member', function () {
    [$owner, $other, $server] = twoMembers();
    $server->members()->updateExistingPivot($other->id, ['role' => 'admin']);
    Passport::actingAs($owner);

    $this->patchJson("/api/servers/{$server->id}/members/{$other->id}/role", ['role' => 'member'])->assertOk();

    expect($server->fresh()->isStaff($other))->toBeFalse();
});

it('refuses to let an admin appoint another admin', function () {
    [, $admin, $server] = twoMembers();
    $third = User::factory()->create();
    $server->members()->attach($third->id, ['role' => 'member']);
    $server->members()->updateExistingPivot($admin->id, ['role' => 'admin']);
    Passport::actingAs($admin);

    $this->patchJson("/api/servers/{$server->id}/members/{$third->id}/role", ['role' => 'admin'])
        ->assertForbidden();
});

it('refuses to write a role for the owner', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    // The owner's standing is `owner_id`, not the pivot; writing one would be a no-op
    // dressed up as a demotion.
    $this->patchJson("/api/servers/{$server->id}/members/{$owner->id}/role", ['role' => 'member'])
        ->assertNotFound();
});

it('refuses a role that is not one of the two', function () {
    [$owner, $other, $server] = twoMembers();
    Passport::actingAs($owner);

    $this->patchJson("/api/servers/{$server->id}/members/{$other->id}/role", ['role' => 'superuser'])
        ->assertStatus(422);
});

it('lets an admin do the things delegated to staff', function () {
    [, $admin, $server] = twoMembers();
    $server->members()->updateExistingPivot($admin->id, ['role' => 'admin']);
    $channel = Channel::factory()->create(['server_id' => $server->id]);
    Passport::actingAs($admin);

    $this->patchJson("/api/servers/{$server->id}", ['name' => 'Renamed'])->assertOk();
    $this->patchJson("/api/channels/{$channel->id}", ['name' => 'renamed'])->assertOk();
    $this->deleteJson("/api/channels/{$channel->id}")->assertNoContent();
});

it('still refuses those things to a plain member', function () {
    [, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);
    Passport::actingAs($member);

    $this->patchJson("/api/servers/{$server->id}", ['name' => 'Renamed'])->assertForbidden();
    $this->patchJson("/api/channels/{$channel->id}", ['name' => 'renamed'])->assertForbidden();
    $this->deleteJson("/api/channels/{$channel->id}")->assertForbidden();
});

it('never lets an admin delete the server', function () {
    [, $admin, $server] = twoMembers();
    $server->members()->updateExistingPivot($admin->id, ['role' => 'admin']);
    Passport::actingAs($admin);

    $this->deleteJson("/api/servers/{$server->id}")->assertForbidden();

    expect(Server::find($server->id))->not->toBeNull();
});

it('gates join-request decisions on staff rather than membership', function () {
    [$owner, $member, $server] = twoMembers();
    $applicant = User::factory()->create();
    $request = $server->joinRequests()->create(['user_id' => $applicant->id]);

    Passport::actingAs($member);
    $this->postJson("/api/servers/{$server->id}/join-requests/approve", ['request_ids' => [$request->id]])
        ->assertForbidden();

    Passport::actingAs($owner);
    $this->postJson("/api/servers/{$server->id}/join-requests/approve", ['request_ids' => [$request->id]])
        ->assertOk();

    expect($server->fresh()->hasMember($applicant))->toBeTrue();
});

it('reports the asker their own role and staff standing', function () {
    [, $admin, $server] = twoMembers();
    $server->members()->updateExistingPivot($admin->id, ['role' => 'admin']);
    Passport::actingAs($admin);

    $this->getJson("/api/servers/{$server->id}")
        ->assertOk()
        ->assertJsonPath('data.role', 'admin')
        ->assertJsonPath('data.is_staff', true)
        ->assertJsonPath('data.is_owner', false);
});
