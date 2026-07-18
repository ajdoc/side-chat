<?php

use App\Events\WhiteboardCleared;
use App\Events\WhiteboardStrokeAdded;
use App\Events\WhiteboardStrokeRemoved;
use App\Events\WhiteboardStrokeUpdated;
use App\Models\SideChat;
use App\Models\User;
use App\Models\WhiteboardStroke;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;

/** A side chat in the owner's channel, with the owner on its roster. */
function sideChatWithParticipant(): array
{
    [$owner, , $channel] = ownerWithChannel();
    $sideChat = SideChat::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);
    $sideChat->participants()->attach($owner->id, ['role' => 'owner']);

    return [$owner, $channel, $sideChat];
}

it('lets a participant commit a stroke and returns the whole board', function () {
    [$owner, , $sideChat] = sideChatWithParticipant();
    Passport::actingAs($owner);

    $this->postJson("/api/side-chats/{$sideChat->id}/whiteboard/strokes", [
        'kind' => 'pen',
        'client_id' => 'c-1',
        'payload' => ['color' => '#111827', 'width' => 3, 'points' => [['x' => 1, 'y' => 2], ['x' => 3, 'y' => 4]]],
    ])->assertCreated()
        ->assertJsonPath('data.kind', 'pen')
        ->assertJsonPath('data.client_id', 'c-1');

    $this->getJson("/api/side-chats/{$sideChat->id}/whiteboard")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.payload.color', '#111827');
});

it('lets a channel member read the board but not draw on it until they join', function () {
    [, $channel, $sideChat] = sideChatWithParticipant();
    // Another member of the same server/channel who has NOT joined the side chat.
    $outsider = User::factory()->create();
    $channel->server->members()->attach($outsider->id, ['role' => 'member']);

    WhiteboardStroke::factory()->create(['side_chat_id' => $sideChat->id]);

    Passport::actingAs($outsider);

    $this->getJson("/api/side-chats/{$sideChat->id}/whiteboard")->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/side-chats/{$sideChat->id}/whiteboard/strokes", [
        'kind' => 'pen',
        'client_id' => 'c-2',
        'payload' => ['points' => [['x' => 0, 'y' => 0]]],
    ])->assertForbidden();
});

it('moves and resizes a stroke in place for a participant, broadcasting it', function () {
    Event::fake([WhiteboardStrokeUpdated::class]);
    [$owner, , $sideChat] = sideChatWithParticipant();
    $stroke = WhiteboardStroke::factory()->create([
        'side_chat_id' => $sideChat->id, 'user_id' => $owner->id, 'kind' => 'note',
        'payload' => ['x' => 0, 'y' => 0, 'text' => 'hi', 'color' => '#fde68a'],
    ]);

    Passport::actingAs($owner);

    $this->patchJson("/api/side-chats/{$sideChat->id}/whiteboard/strokes/{$stroke->id}", [
        'payload' => ['x' => 120, 'y' => 80, 'w' => 240, 'text' => 'hi', 'color' => '#fde68a'],
    ])->assertOk()
        ->assertJsonPath('data.payload.x', 120)
        ->assertJsonPath('data.payload.w', 240);

    expect($stroke->fresh()->payload['x'])->toBe(120)
        ->and($stroke->fresh()->payload['w'])->toBe(240);
    Event::assertDispatched(WhiteboardStrokeUpdated::class);
});

it('forbids a non-participant from moving a stroke', function () {
    [, $channel, $sideChat] = sideChatWithParticipant();
    $outsider = User::factory()->create();
    $channel->server->members()->attach($outsider->id, ['role' => 'member']);
    $stroke = WhiteboardStroke::factory()->create(['side_chat_id' => $sideChat->id]);

    Passport::actingAs($outsider);

    $this->patchJson("/api/side-chats/{$sideChat->id}/whiteboard/strokes/{$stroke->id}", [
        'payload' => ['x' => 5, 'y' => 5],
    ])->assertForbidden();
});

it('erases a single stroke and clears the whole board', function () {
    [$owner, , $sideChat] = sideChatWithParticipant();
    $a = WhiteboardStroke::factory()->create(['side_chat_id' => $sideChat->id, 'user_id' => $owner->id]);
    $b = WhiteboardStroke::factory()->create(['side_chat_id' => $sideChat->id, 'user_id' => $owner->id]);

    Passport::actingAs($owner);

    $this->deleteJson("/api/side-chats/{$sideChat->id}/whiteboard/strokes/{$a->id}")->assertNoContent();
    expect(WhiteboardStroke::whereKey($a->id)->exists())->toBeFalse()
        ->and(WhiteboardStroke::whereKey($b->id)->exists())->toBeTrue();

    $this->deleteJson("/api/side-chats/{$sideChat->id}/whiteboard")->assertNoContent();
    expect($sideChat->whiteboardStrokes()->count())->toBe(0);
});

it('will not erase a stroke that belongs to another side chat', function () {
    [$owner, $channel, $sideChat] = sideChatWithParticipant();
    $other = SideChat::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);
    $stray = WhiteboardStroke::factory()->create(['side_chat_id' => $other->id]);

    Passport::actingAs($owner);

    $this->deleteJson("/api/side-chats/{$sideChat->id}/whiteboard/strokes/{$stray->id}")->assertNotFound();
    expect(WhiteboardStroke::whereKey($stray->id)->exists())->toBeTrue();
});

it('broadcasts each board change on the side chat stream', function () {
    Event::fake([WhiteboardStrokeAdded::class, WhiteboardStrokeRemoved::class, WhiteboardCleared::class]);

    [$owner, , $sideChat] = sideChatWithParticipant();
    Passport::actingAs($owner);

    $stroke = $this->postJson("/api/side-chats/{$sideChat->id}/whiteboard/strokes", [
        'kind' => 'rect', 'client_id' => 'c-3',
        'payload' => ['color' => '#000', 'x1' => 0, 'y1' => 0, 'x2' => 10, 'y2' => 10],
    ])->json('data.id');

    $this->deleteJson("/api/side-chats/{$sideChat->id}/whiteboard/strokes/{$stroke}");
    $this->deleteJson("/api/side-chats/{$sideChat->id}/whiteboard");

    Event::assertDispatched(WhiteboardStrokeAdded::class);
    Event::assertDispatched(WhiteboardStrokeRemoved::class);
    Event::assertDispatched(WhiteboardCleared::class);
});

it('cascades strokes when the side chat is deleted', function () {
    [, , $sideChat] = sideChatWithParticipant();
    WhiteboardStroke::factory()->count(3)->create(['side_chat_id' => $sideChat->id]);

    $sideChat->delete();

    expect(WhiteboardStroke::where('side_chat_id', $sideChat->id)->count())->toBe(0);
});
