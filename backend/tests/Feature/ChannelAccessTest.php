<?php

use App\Models\Channel;
use App\Models\User;
use Laravel\Passport\Passport;

/*
 * Private channels.
 *
 * The rule lives in exactly one place — Channel::hasMember — which is why these tests are
 * mostly about *reach*: that the flag is felt by the sidebar, by an endpoint addressed at
 * the channel, and by an endpoint addressed at something *inside* the channel. That last
 * one is the trapdoor: before this, membership was answered by the container, so anything
 * that walked up to the server (a message, a thread, a widget) would have skipped the
 * channel's own gate entirely.
 */

it('leaves a channel open to the whole server by default', function () {
    [, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);
    Passport::actingAs($member);

    // `fresh()`, not the in-memory model: the default lives on the column, so the instance
    // the factory just built hasn't read it back yet.
    expect($channel->fresh()->is_private)->toBeFalse();
    $this->getJson("/api/channels/{$channel->id}/messages")->assertOk();
});

it('lets staff restrict a channel to an allow-list', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/access", [
        'is_private' => true,
        'member_ids' => [$member->id],
    ])->assertOk()->assertJsonPath('data.is_private', true);

    expect($channel->fresh()->allowedMembers()->pluck('users.id')->all())->toBe([$member->id]);
});

it('refuses to let a plain member change access', function () {
    [, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);
    Passport::actingAs($member);

    $this->putJson("/api/channels/{$channel->id}/access", ['is_private' => true])->assertForbidden();
});

it('shuts an excluded member out of the channel entirely', function () {
    [$owner, $outsider, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id, 'is_private' => true]);
    Passport::actingAs($outsider);

    $this->getJson("/api/channels/{$channel->id}/messages")->assertForbidden();
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'hi'])->assertForbidden();

    // …and the people who *are* allowed still get in.
    $channel->allowedMembers()->attach($outsider->id);
    $this->getJson("/api/channels/{$channel->id}/messages")->assertOk();

    // The owner never needed the list.
    Passport::actingAs($owner);
    $this->getJson("/api/channels/{$channel->id}/messages")->assertOk();
});

it('lets an admin into a private channel they are not listed in', function () {
    [, $admin, $server] = twoMembers();
    $server->members()->updateExistingPivot($admin->id, ['role' => 'admin']);
    $channel = Channel::factory()->create(['server_id' => $server->id, 'is_private' => true]);
    Passport::actingAs($admin);

    $this->getJson("/api/channels/{$channel->id}/messages")->assertOk();
});

it('hides a private channel from the sidebar of anyone not in it', function () {
    [$owner, $outsider, $server] = twoMembers();
    $open = Channel::factory()->create(['server_id' => $server->id]);
    $secret = Channel::factory()->create(['server_id' => $server->id, 'is_private' => true]);

    Passport::actingAs($outsider);
    $ids = collect($this->getJson("/api/servers/{$server->id}/channels")->assertOk()->json('data'))->pluck('id');
    expect($ids)->toContain($open->id)->not->toContain($secret->id);

    // Staff see everything — they're the ones who decide what's private.
    Passport::actingAs($owner);
    $ids = collect($this->getJson("/api/servers/{$server->id}/channels")->assertOk()->json('data'))->pluck('id');
    expect($ids)->toContain($secret->id);
});

it('gates things addressed *inside* a private channel too', function () {
    [$owner, $outsider, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);

    Passport::actingAs($owner);
    $message = $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'secret'])
        ->assertCreated()->json('data.id');
    $thread = $this->postJson("/api/channels/{$channel->id}/threads", ['name' => 'plans'])
        ->assertCreated()->json('data.id');

    $channel->update(['is_private' => true]);

    // The thread and the message both walk up to the same server the outsider is in —
    // the channel's own gate is the only thing standing between them and the history.
    Passport::actingAs($outsider);
    $this->getJson("/api/threads/{$thread}/messages")->assertForbidden();
    $this->postJson("/api/messages/{$message}/reactions", ['emoji' => '👍'])->assertForbidden();
});

it('drops non-members from a submitted allow-list instead of failing', function () {
    [$owner, $member, $server] = twoMembers();
    $stranger = User::factory()->create(); // never joined this server
    $channel = Channel::factory()->create(['server_id' => $server->id]);
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/access", [
        'is_private' => true,
        'member_ids' => [$member->id, $stranger->id],
    ])->assertOk();

    expect($channel->fresh()->allowedMembers()->pluck('users.id')->all())->toBe([$member->id]);
});

it('clears the allow-list when a channel goes public again', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id, 'is_private' => true]);
    $channel->allowedMembers()->attach($member->id);
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/access", ['is_private' => false])->assertOk();

    expect($channel->fresh()->is_private)->toBeFalse()
        ->and($channel->fresh()->allowedMembers()->count())->toBe(0);
});

it('keeps the allow-list away from anyone who cannot set it', function () {
    [, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);
    Passport::actingAs($member);

    $this->getJson("/api/channels/{$channel->id}/access")->assertForbidden();
});
