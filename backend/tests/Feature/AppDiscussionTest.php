<?php

use App\Models\AppDiscussion;
use App\Models\KanbanCard;
use App\Models\Message;
use App\Models\SideChat;
use App\Models\User;
use App\Support\Apps\KanbanBoards;
use Laravel\Passport\Passport;

/**
 * "Talk about this in chat" — a side chat opened from an app item.
 *
 * The cases that matter are the ones where being wrong splits a conversation: a second press
 * making a second room, and an item's link surviving (or not) the things that can be deleted
 * around it.
 */
function cardIn($channel, User $owner, string $text = 'redo the onboarding'): KanbanCard
{
    return KanbanBoards::for($channel, $owner)->cards()->create([
        'channel_id' => $channel->id, 'column' => 'todo', 'text' => $text,
    ]);
}

it('opens a side chat named after the item, anchored in the timeline', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $card = cardIn($channel, $owner);

    $res = $this->postJson("/api/channels/{$channel->id}/apps/kanban_card/{$card->id}/discussion")
        ->assertCreated()->json('data');

    $sideChat = SideChat::sole();

    expect($res['side_chat_id'])->toBe($sideChat->id)
        ->and($sideChat->name)->toBe('redo the onboarding')
        // The anchor is what makes the conversation findable by anyone scrolling the channel —
        // a side chat with no origin message is invisible to everyone who wasn't told.
        ->and($sideChat->message_id)->not->toBeNull()
        ->and(Message::find($sideChat->message_id)->type)->toBe('system');

    // And the link is readable from the item afterwards.
    expect($this->getJson("/api/channels/{$channel->id}/apps/kanban_card/{$card->id}/discussion")
        ->assertOk()->json('data.side_chat_id'))->toBe($sideChat->id);
});

it('joins the existing room instead of opening a second one', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $other = User::factory()->create();
    $server->members()->attach($other->id);

    Passport::actingAs($owner);
    $card = cardIn($channel, $owner);
    $first = $this->postJson("/api/channels/{$channel->id}/apps/kanban_card/{$card->id}/discussion")
        ->assertCreated()->json('data.side_chat_id');

    // The second person pressing the button is the whole reason this is idempotent: two rooms
    // about one card is the split the feature exists to prevent.
    Passport::actingAs($other);
    $second = $this->postJson("/api/channels/{$channel->id}/apps/kanban_card/{$card->id}/discussion")
        ->assertOk()->json('data.side_chat_id');

    expect($second)->toBe($first)
        ->and(SideChat::count())->toBe(1);
});

it('names a tracker task by its key', function () {
    [$owner, , $channel] = ownerWithChannel();
    $project = $channel->trackerProjects()->create(['key' => 'ONB', 'name' => 'Onboarding', 'created_by' => $owner->id]);
    $task = $project->tasks()->create(['number' => 4, 'title' => 'Rewrite the welcome email']);

    Passport::actingAs($owner);
    $this->postJson("/api/channels/{$channel->id}/apps/tracker_task/{$task->id}/discussion")->assertCreated();

    // "ONB-4" is how people already refer to it in chat, so it's what the room is called.
    expect(SideChat::sole()->name)->toBe('ONB-4 Rewrite the welcome email');
});

it('keeps the conversation when the item is deleted, and drops the pointer', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $card = cardIn($channel, $owner);

    $this->postJson("/api/channels/{$channel->id}/apps/kanban_card/{$card->id}/discussion")->assertCreated();
    $this->deleteJson("/api/channels/{$channel->id}/kanban/cards/{$card->id}")->assertNoContent();

    // The people in that room didn't consent to losing it because a card was tidied.
    expect(SideChat::count())->toBe(1)
        ->and(AppDiscussion::count())->toBe(0);
});

it('refuses an item in a channel the caller cannot see', function () {
    [$owner, , $channel] = ownerWithChannel();
    $card = cardIn($channel, $owner);

    Passport::actingAs(User::factory()->create());

    $this->postJson("/api/channels/{$channel->id}/apps/kanban_card/{$card->id}/discussion")->assertForbidden();
});
