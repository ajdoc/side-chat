<?php

use App\Models\Message;
use App\Models\Thread;
use Laravel\Passport\Passport;

/*
 * Renaming and deleting a thread: who may, and what a deletion takes with it.
 */

it('lets the thread creator rename it', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $thread = $this->postJson("/api/channels/{$channel->id}/threads", ['name' => 'old'])
        ->assertCreated()->json('data.id');

    $this->patchJson("/api/threads/{$thread}", ['name' => 'new'])
        ->assertOk()
        ->assertJsonPath('data.name', 'new');
});

it('lets an admin rename somebody else’s thread', function () {
    [$owner, $admin, $server] = twoMembers();
    $server->members()->updateExistingPivot($admin->id, ['role' => 'admin']);
    $channel = \App\Models\Channel::factory()->create(['server_id' => $server->id]);

    Passport::actingAs($owner);
    $thread = $this->postJson("/api/channels/{$channel->id}/threads", ['name' => 'old'])
        ->assertCreated()->json('data.id');

    Passport::actingAs($admin);
    $this->patchJson("/api/threads/{$thread}", ['name' => 'moderated'])->assertOk();
});

it('refuses to let an ordinary member rename or delete somebody else’s thread', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = \App\Models\Channel::factory()->create(['server_id' => $server->id]);

    Passport::actingAs($owner);
    $thread = $this->postJson("/api/channels/{$channel->id}/threads", ['name' => 'mine'])
        ->assertCreated()->json('data.id');

    Passport::actingAs($member);
    $this->patchJson("/api/threads/{$thread}", ['name' => 'yours'])->assertForbidden();
    $this->deleteJson("/api/threads/{$thread}")->assertForbidden();
});

it('deletes a thread and every reply in it, leaving the parent message alone', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $parent = $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'the topic'])
        ->assertCreated()->json('data.id');
    $thread = $this->postJson("/api/channels/{$channel->id}/threads", ['name' => 'about it', 'message_id' => $parent])
        ->assertCreated()->json('data.id');
    $this->postJson("/api/threads/{$thread}/messages", ['body' => 'a reply'])->assertCreated();

    $this->deleteJson("/api/threads/{$thread}")->assertNoContent();

    expect(Thread::find($thread))->toBeNull()
        ->and(Message::where('thread_id', $thread)->count())->toBe(0)
        // The message it branched off is a message in its own right and survives.
        ->and(Message::find($parent))->not->toBeNull();
});

it('tells the client whether the asker may manage the thread', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = \App\Models\Channel::factory()->create(['server_id' => $server->id]);

    Passport::actingAs($owner);
    $thread = $this->postJson("/api/channels/{$channel->id}/threads", ['name' => 'mine'])
        ->assertCreated()->json('data.id');

    $this->getJson("/api/threads/{$thread}")->assertJsonPath('data.can_manage', true);

    Passport::actingAs($member);
    $this->getJson("/api/threads/{$thread}")->assertJsonPath('data.can_manage', false);
});
