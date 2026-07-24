<?php

use App\Models\CanvasItem;
use App\Models\User;
use Laravel\Passport\Passport;

/**
 * The Open Canvas store/update path, and specifically the regression that a note or checklist
 * card — which carries no `content.type` — used to write null into the NOT NULL `content`
 * column, because Laravel's validated() drops a parent array whose only ruled child is absent.
 */
it('creates a note card and persists its free-form content', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/canvas", [
        'kind' => 'note',
        'content' => ['text' => 'hello board'],
        'x' => 10, 'y' => 20, 'w' => 220, 'h' => 180,
    ])->assertCreated()
        ->assertJsonPath('data.kind', 'note')
        ->assertJsonPath('data.content.text', 'hello board');

    expect(CanvasItem::where('channel_id', $channel->id)->value('content'))
        ->toBe(['text' => 'hello board']);
});

it('creates a checklist card with an empty items list', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/canvas", [
        'kind' => 'todo',
        'content' => ['title' => 'Checklist', 'items' => []],
        'x' => 0, 'y' => 0,
    ])->assertCreated()
        ->assertJsonPath('data.content.title', 'Checklist');
});

it('edits a note card content', function () {
    [$owner, , $channel] = ownerWithChannel();
    $item = CanvasItem::create([
        'channel_id' => $channel->id, 'user_id' => $owner->id,
        'kind' => 'note', 'content' => ['text' => 'first'], 'z' => 1,
    ]);

    Passport::actingAs($owner);

    $this->patchJson("/api/channels/{$channel->id}/canvas/{$item->id}", [
        'content' => ['text' => 'edited'],
    ])->assertOk()->assertJsonPath('data.content.text', 'edited');

    expect($item->fresh()->content)->toBe(['text' => 'edited']);
});

it('leaves content untouched when only geometry is patched', function () {
    [$owner, , $channel] = ownerWithChannel();
    $item = CanvasItem::create([
        'channel_id' => $channel->id, 'user_id' => $owner->id,
        'kind' => 'note', 'content' => ['text' => 'keep me'], 'z' => 1,
    ]);

    Passport::actingAs($owner);

    $this->patchJson("/api/channels/{$channel->id}/canvas/{$item->id}", [
        'x' => 99, 'y' => 88,
    ])->assertOk();

    expect($item->fresh()->content)->toBe(['text' => 'keep me']);
    expect($item->fresh()->x)->toBe(99);
});

it('forbids a non-member from the channel canvas', function () {
    [, , $channel] = ownerWithChannel();
    Passport::actingAs(User::factory()->create());

    $this->postJson("/api/channels/{$channel->id}/canvas", [
        'kind' => 'note', 'content' => ['text' => 'x'],
    ])->assertForbidden();
});
