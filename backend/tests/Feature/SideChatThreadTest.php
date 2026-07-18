<?php

use App\Models\Message;
use App\Models\SideChat;
use App\Models\Thread;
use App\Models\User;
use Laravel\Passport\Passport;

/** A side chat in the owner's channel, with the owner on its roster. */
function sideChatForThreads(): array
{
    [$owner, , $channel] = ownerWithChannel();
    $sideChat = SideChat::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);
    $sideChat->participants()->attach($owner->id, ['role' => 'owner']);

    return [$owner, $channel, $sideChat];
}

it('creates a thread scoped to the side chat, off one of its messages', function () {
    [$owner, , $sideChat] = sideChatForThreads();
    $parent = Message::factory()->create([
        'channel_id' => $sideChat->channel_id, 'side_chat_id' => $sideChat->id, 'user_id' => $owner->id,
    ]);

    Passport::actingAs($owner);

    $this->postJson("/api/side-chats/{$sideChat->id}/threads", [
        'name' => 'Spec details', 'message_id' => $parent->id,
    ])->assertCreated()
        ->assertJsonPath('data.side_chat_id', $sideChat->id)
        ->assertJsonPath('data.channel_id', $sideChat->channel_id)
        ->assertJsonPath('data.message_id', $parent->id);

    $this->getJson("/api/side-chats/{$sideChat->id}/threads")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Spec details');
});

it('exposes the started thread on the side chat message it branched from', function () {
    [$owner, , $sideChat] = sideChatForThreads();
    $parent = Message::factory()->create([
        'channel_id' => $sideChat->channel_id, 'side_chat_id' => $sideChat->id, 'user_id' => $owner->id,
    ]);
    $thread = Thread::factory()->create([
        'channel_id' => $sideChat->channel_id, 'side_chat_id' => $sideChat->id,
        'user_id' => $owner->id, 'message_id' => $parent->id, 'name' => 'Design',
    ]);
    Message::factory()->count(2)->create([
        'channel_id' => $sideChat->channel_id, 'thread_id' => $thread->id, 'user_id' => $owner->id,
    ]);

    Passport::actingAs($owner);

    $this->getJson("/api/side-chats/{$sideChat->id}/messages")
        ->assertOk()
        ->assertJsonPath('data.0.started_thread.id', $thread->id)
        ->assertJsonPath('data.0.started_thread.name', 'Design')
        ->assertJsonPath('data.0.started_thread.replies_count', 2);
});

it('keeps side-chat threads out of the channel Threads list, and vice versa', function () {
    [$owner, $channel, $sideChat] = sideChatForThreads();

    $channelThread = Thread::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id, 'name' => 'Channel-level']);
    $sideThread = Thread::factory()->create([
        'channel_id' => $channel->id, 'side_chat_id' => $sideChat->id, 'user_id' => $owner->id, 'name' => 'Side-only',
    ]);

    Passport::actingAs($owner);

    $channelList = $this->getJson("/api/channels/{$channel->id}/threads")->assertOk()->json('data');
    expect(collect($channelList)->pluck('name')->all())->toBe(['Channel-level']);

    $sideList = $this->getJson("/api/side-chats/{$sideChat->id}/threads")->assertOk()->json('data');
    expect(collect($sideList)->pluck('name')->all())->toBe(['Side-only']);
});

it('forbids a non-participant from starting a side-chat thread', function () {
    [, $channel, $sideChat] = sideChatForThreads();
    $member = User::factory()->create();
    $channel->server->members()->attach($member->id, ['role' => 'member']);

    Passport::actingAs($member);

    // A channel member may read the workspace's thread list …
    $this->getJson("/api/side-chats/{$sideChat->id}/threads")->assertOk();
    // … but not start one until they join the side chat.
    $this->postJson("/api/side-chats/{$sideChat->id}/threads", ['name' => 'Nope'])->assertForbidden();
});
