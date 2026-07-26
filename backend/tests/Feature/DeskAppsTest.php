<?php

use App\Models\User;
use Laravel\Passport\Passport;

/**
 * Which apps a Side Desk shows. The strip is per surface and shared by everyone on it, and the
 * two things worth pinning down are that "never customised" stays null (so the client's defaults
 * can change in a later release) and that nothing unrenderable can be stored.
 */
it('returns null until the strip is customised', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->getJson("/api/channels/{$channel->id}/desk-apps")
        ->assertOk()
        ->assertJsonPath('apps', null);
});

it('stores the strip in the order it was sent', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/desk-apps", [
        'apps' => ['calendar', 'board', 'kanban'],
    ])->assertOk()->assertJsonPath('apps', ['calendar', 'board', 'kanban']);

    expect($channel->fresh()->desk_apps)->toBe(['calendar', 'board', 'kanban']);
});

it('accepts a widget promoted to an app', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/desk-apps", [
        'apps' => ['skribbl', 'poll', 'music'],
    ])->assertOk();

    expect($channel->fresh()->desk_apps)->toContain('skribbl');
});

it('refuses an app id nothing can render', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->putJson("/api/channels/{$channel->id}/desk-apps", [
        'apps' => ['board', 'nonsense'],
    ])->assertStatus(422);
});

it('refuses the same app twice', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    // Two tabs over one piece of state, where removing "the" tab leaves its twin behind.
    $this->putJson("/api/channels/{$channel->id}/desk-apps", [
        'apps' => ['board', 'board'],
    ])->assertStatus(422);
});

it('can empty the strip down to the implicit canvas', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    // The Open Canvas is never stored — the client pins it — so an empty array is a valid desk.
    $this->putJson("/api/channels/{$channel->id}/desk-apps", ['apps' => []])->assertOk();

    expect($channel->fresh()->desk_apps)->toBe([]);
});

it('keeps non-members out', function () {
    [, , $channel] = ownerWithChannel();
    Passport::actingAs(User::factory()->create());

    $this->getJson("/api/channels/{$channel->id}/desk-apps")->assertForbidden();
    $this->putJson("/api/channels/{$channel->id}/desk-apps", ['apps' => ['board']])->assertForbidden();
});

/**
 * The other half of the apps/widgets unification: a widget app tab resolves the channel's
 * existing widget rather than making one of its own, which is why a tab, a canvas card and a
 * timeline card can't drift apart.
 */
it('opens the channel widget of a type, and the same one twice', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    // 201 the first time and 200 the second: a JsonResource reports the created status off the
    // model's `wasRecentlyCreated`, which is exactly the get-or-create distinction being tested.
    $first = $this->postJson("/api/channels/{$channel->id}/widgets/ensure", ['type' => 'kanban'])
        ->assertCreated()->json('data.id');

    $second = $this->postJson("/api/channels/{$channel->id}/widgets/ensure", ['type' => 'kanban'])
        ->assertOk()->json('data.id');

    expect($second)->toBe($first);
});

it('refuses to open a widget type that does not exist', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/widgets/ensure", ['type' => 'solitaire'])
        ->assertStatus(422);
});
