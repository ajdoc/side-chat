<?php

use App\Events\WhiteboardCleared;
use App\Events\WhiteboardStrokeAdded;
use App\Events\WhiteboardStrokeRemoved;
use App\Models\User;
use App\Models\WhiteboardStroke;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;

it('lets any channel member draw on and read the channel board', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/whiteboard/strokes", [
        'kind' => 'pen',
        'client_id' => 'c-1',
        'payload' => ['color' => '#111827', 'width' => 3, 'points' => [['x' => 1, 'y' => 2], ['x' => 3, 'y' => 4]]],
    ])->assertCreated()->assertJsonPath('data.kind', 'pen');

    $this->getJson("/api/channels/{$channel->id}/whiteboard")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.payload.color', '#111827');

    // The stroke belongs to the channel, not to any side chat.
    expect(WhiteboardStroke::whereNotNull('channel_id')->whereNull('side_chat_id')->count())->toBe(1);
});

it('resizes a text stroke on the channel board', function () {
    [$owner, , $channel] = ownerWithChannel();
    $stroke = WhiteboardStroke::factory()->create([
        'channel_id' => $channel->id, 'side_chat_id' => null, 'user_id' => $owner->id, 'kind' => 'text',
        'payload' => ['x' => 0, 'y' => 0, 'text' => 'a', 'color' => '#111827', 'width' => 18],
    ]);

    Passport::actingAs($owner);

    $this->patchJson("/api/channels/{$channel->id}/whiteboard/strokes/{$stroke->id}", [
        'payload' => ['x' => 50, 'y' => 60, 'text' => 'a', 'color' => '#111827', 'width' => 40],
    ])->assertOk()->assertJsonPath('data.payload.width', 40);

    expect($stroke->fresh()->payload['width'])->toBe(40);
});

it('forbids a non-member from the channel board', function () {
    [, , $channel] = ownerWithChannel();
    Passport::actingAs(User::factory()->create());

    $this->getJson("/api/channels/{$channel->id}/whiteboard")->assertForbidden();
    $this->postJson("/api/channels/{$channel->id}/whiteboard/strokes", [
        'kind' => 'pen', 'client_id' => 'c-2', 'payload' => ['points' => [['x' => 0, 'y' => 0]]],
    ])->assertForbidden();
});

it('erases one stroke and clears the channel board, broadcasting each change', function () {
    Event::fake([WhiteboardStrokeAdded::class, WhiteboardStrokeRemoved::class, WhiteboardCleared::class]);

    [$owner, , $channel] = ownerWithChannel();
    $a = WhiteboardStroke::factory()->create(['channel_id' => $channel->id, 'side_chat_id' => null, 'user_id' => $owner->id]);
    WhiteboardStroke::factory()->create(['channel_id' => $channel->id, 'side_chat_id' => null, 'user_id' => $owner->id]);

    Passport::actingAs($owner);

    $this->deleteJson("/api/channels/{$channel->id}/whiteboard/strokes/{$a->id}")->assertNoContent();
    expect(WhiteboardStroke::whereKey($a->id)->exists())->toBeFalse();

    $this->deleteJson("/api/channels/{$channel->id}/whiteboard")->assertNoContent();
    expect($channel->whiteboardStrokes()->count())->toBe(0);

    Event::assertDispatched(WhiteboardStrokeRemoved::class);
    Event::assertDispatched(WhiteboardCleared::class);
});

it('will not erase a stroke that belongs to another channel', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $other = \App\Models\Channel::factory()->create(['server_id' => $server->id]);
    $stray = WhiteboardStroke::factory()->create(['channel_id' => $other->id, 'side_chat_id' => null]);

    Passport::actingAs($owner);

    $this->deleteJson("/api/channels/{$channel->id}/whiteboard/strokes/{$stray->id}")->assertNotFound();
    expect(WhiteboardStroke::whereKey($stray->id)->exists())->toBeTrue();
});
