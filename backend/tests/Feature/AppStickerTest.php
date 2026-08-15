<?php

use App\Models\User;
use Laravel\Passport\Passport;

/**
 * The Sticker Wall.
 *
 * The one rule that differs from every other Side Desk app is ownership: a wall is a collage of
 * individual contributions, so moving and deleting are yours-or-staff rather than
 * anyone-in-the-channel. That's most of what's worth testing here.
 */
it('places a sticker on top of the wall', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $first = $this->postJson("/api/channels/{$channel->id}/stickers", [
        'name' => 'First', 'content' => ['shape' => 'star', 'paths' => []],
    ])->assertCreated()->json('data');

    $second = $this->postJson("/api/channels/{$channel->id}/stickers", [
        'name' => 'Second', 'content' => ['shape' => 'heart', 'paths' => []],
    ])->assertCreated()->json('data');

    // Newest on top, which is what a physical wall does.
    expect($second['z'])->toBeGreaterThan($first['z']);
});

it('lets you move your own sticker', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $s = $this->postJson("/api/channels/{$channel->id}/stickers", [
        'content' => ['shape' => 'square', 'paths' => []],
    ])->json('data');

    $this->patchJson("/api/channels/{$channel->id}/stickers/{$s['id']}", ['x' => 420, 'y' => 90])
        ->assertOk()
        ->assertJsonPath('data.x', 420)
        ->assertJsonPath('data.y', 90);
});

it('refuses to let one member move or delete another’s sticker', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $s = $this->postJson("/api/channels/{$channel->id}/stickers", [
        'content' => ['shape' => 'square', 'paths' => []],
    ])->json('data');

    // An ordinary member of the same channel — allowed to see the wall, not to rearrange
    // somebody else's contribution to it.
    $other = User::factory()->create();
    $server->members()->attach($other->id, ['role' => 'member']);
    Passport::actingAs($other);

    $this->patchJson("/api/channels/{$channel->id}/stickers/{$s['id']}", ['x' => 0])->assertForbidden();
    $this->deleteJson("/api/channels/{$channel->id}/stickers/{$s['id']}")->assertForbidden();

    // But they can still add their own, and see everything.
    $this->postJson("/api/channels/{$channel->id}/stickers", [
        'content' => ['shape' => 'circle', 'paths' => []],
    ])->assertCreated();

    $this->getJson("/api/channels/{$channel->id}/stickers")->assertOk()->assertJsonCount(2, 'data');
});

it('lets staff moderate the wall', function () {
    [$owner, $server, $channel] = ownerWithChannel();

    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);
    Passport::actingAs($member);

    $s = $this->postJson("/api/channels/{$channel->id}/stickers", [
        'content' => ['shape' => 'square', 'paths' => []],
    ])->json('data');

    // A wall needs moderating, so staff keep the override.
    Passport::actingAs($owner);
    $this->deleteJson("/api/channels/{$channel->id}/stickers/{$s['id']}")->assertNoContent();
});

it('keeps each channel’s wall to itself', function () {
    [$owner, , $channel] = ownerWithChannel();
    [$owner2, , $channel2] = ownerWithChannel();

    Passport::actingAs($owner);
    $s = $this->postJson("/api/channels/{$channel->id}/stickers", [
        'content' => ['shape' => 'square', 'paths' => []],
    ])->json('data');

    Passport::actingAs($owner2);
    $this->patchJson("/api/channels/{$channel2->id}/stickers/{$s['id']}", ['x' => 0])->assertNotFound();
    $this->getJson("/api/channels/{$channel2->id}/stickers")->assertOk()->assertJsonCount(0, 'data');
});

it('keeps the drawing out of the broadcast, and serves it over HTTP', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $drawing = ['shape' => 'star', 'layers' => [[
        'name' => 'Ink', 'visible' => true,
        'paths' => [['points' => [[0, 0], [50, 50]], 'color' => '#000', 'width' => 3]],
    ]]];

    $s = $this->postJson("/api/channels/{$channel->id}/stickers", ['content' => $drawing])
        ->assertCreated()->json('data');

    // The HTTP response carries the drawing — that's how a wall loads.
    expect($s)->toHaveKey('content');

    // The broadcast must not: a sticker's strokes are unbounded and comfortably past the 10KB
    // a Pusher/Reverb event may carry, which is a hard limit on Laravel Cloud.
    $payload = App\Http\Resources\AppStickerResource::reference(
        App\Models\AppSticker::find($s['id'])
    )->resolve();

    expect($payload)->not->toHaveKey('content')
        ->and($payload)->toHaveKeys(['id', 'x', 'y', 'z', 'w', 'h', 'rotation'])
        ->and(strlen((string) json_encode($payload)))->toBeLessThan(10_000);

    // And the drawing is fetchable on its own, which is what the client does after a broadcast.
    $this->getJson("/api/channels/{$channel->id}/stickers/{$s['id']}")
        ->assertOk()
        ->assertJsonPath('data.content.shape', 'star');
});

it('refuses a drawing too big to belong on a wall', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    // A client guard is not a gate — a hand-written request must be refused too.
    $huge = ['shape' => 'square', 'layers' => [[
        'name' => 'L', 'visible' => true,
        'paths' => [['points' => array_fill(0, 20000, [12.3, 45.6]), 'color' => '#000', 'width' => 3]],
    ]]];

    $this->postJson("/api/channels/{$channel->id}/stickers", ['content' => $huge])
        ->assertStatus(422)
        ->assertJsonValidationErrors('content');
});
