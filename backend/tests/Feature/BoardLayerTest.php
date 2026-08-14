<?php

use App\Models\User;
use App\Models\WhiteboardStroke;
use Laravel\Passport\Passport;

/**
 * Board layers.
 *
 * The property that matters is that layers are *additive*: every board that existed before them
 * has its strokes on layer 0, and nothing about it moves.
 */
it('puts a stroke on layer 0 when the client says nothing', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $res = $this->postJson("/api/channels/{$channel->id}/whiteboard/strokes", [
        'kind' => 'pen', 'client_id' => 'a1', 'payload' => ['points' => [['x' => 0, 'y' => 0]]],
    ])->assertCreated();

    // A client a release behind draws on the one layer every board already had.
    expect($res->json('data.layer'))->toBe(0);
});

it('stores and returns a stroke’s layer', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/whiteboard/strokes", [
        'kind' => 'pen', 'client_id' => 'a2', 'payload' => ['points' => [['x' => 1, 'y' => 1]]], 'layer' => 3,
    ])->assertCreated()->assertJsonPath('data.layer', 3);

    expect(WhiteboardStroke::where('client_id', 'a2')->value('layer'))->toBe(3);
});

it('returns strokes in layer order, then age', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    // Drawn newest-first by layer, so plain id order would give the wrong answer.
    foreach ([['top', 2], ['bottom', 0], ['middle', 1]] as [$id, $layer]) {
        $this->postJson("/api/channels/{$channel->id}/whiteboard/strokes", [
            'kind' => 'pen', 'client_id' => $id, 'payload' => ['points' => [['x' => 0, 'y' => 0]]], 'layer' => $layer,
        ])->assertCreated();
    }

    $order = collect($this->getJson("/api/channels/{$channel->id}/whiteboard")->json("data"))
        ->pluck('client_id')->all();

    expect($order)->toBe(['bottom', 'middle', 'top']);
});

it('refuses a layer outside the allowed range', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/whiteboard/strokes", [
        'kind' => 'pen', 'client_id' => 'bad', 'payload' => ['points' => [['x' => 0, 'y' => 0]]], 'layer' => 999,
    ])->assertStatus(422)->assertJsonValidationErrors('layer');
});

it('reports null layers until the board uses them, then stores what was sent', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    // Null, not a default: the client renders that as the single unnamed layer, so a later
    // release can change what that default looks like.
    $this->getJson("/api/channels/{$channel->id}/whiteboard/layers")
        ->assertOk()->assertJsonPath('layers', null);

    $this->putJson("/api/channels/{$channel->id}/whiteboard/layers", [
        'layers' => [
            ['name' => 'Background', 'visible' => true],
            ['name' => 'Sketch', 'visible' => false],
        ],
    ])->assertOk()->assertJsonPath('layers.1.name', 'Sketch');

    expect($channel->fresh()->board_layers)->toHaveCount(2);
});

it('refuses layer edits from someone outside the channel', function () {
    [, , $channel] = ownerWithChannel();
    Passport::actingAs(User::factory()->create());

    $this->putJson("/api/channels/{$channel->id}/whiteboard/layers", ['layers' => []])
        ->assertForbidden();
});
