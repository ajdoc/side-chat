<?php

use App\Models\Channel;
use App\Models\SideChat;
use App\Models\SideChatForum;
use App\Models\User;
use Laravel\Passport\Passport;

/*
 * Searching the *named places* inside a channel: side chats, threads, and the groups the
 * side chat list folds under.
 *
 * The interesting rule here is an asymmetry, and it's the one thing in this file worth
 * getting right. A side chat's **title** is public to its channel — the list panel shows
 * every post to every member, joined or not — while its **messages** are not. So a title
 * search must find posts you haven't joined, and a message search must still not leak a
 * word of what's inside them. Both halves are asserted below, together, because it is
 * exactly the kind of pair that a later "tightening" of one breaks silently.
 *
 * Threads go the other way: a channel thread is as open as its channel, a side chat's own
 * thread inherits that side chat's roster.
 */

/** Search as this person for `$term`, returning rows of one surface type. */
function searchSurfaces(User $user, string $term, string $type): array
{
    Passport::actingAs($user);

    return test()->getJson('/api/search?'.http_build_query(['q' => $term, 'type' => $type]))
        ->assertOk()
        ->json('data');
}

it('finds a side chat by its title', function () {
    [$owner, , $channel] = ownerWithChannel();
    SideChat::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id, 'name' => 'Pangolin deploy plan']);

    $rows = searchSurfaces($owner, 'pangolin', 'side_chats');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['kind'])->toBe('side_chat')
        ->and($rows[0]['name'])->toBe('Pangolin deploy plan')
        ->and($rows[0]['channel_name'])->toBe($channel->name);
});

it('finds a side chat you have not joined, but still none of what is said in it', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);
    $sideChat = SideChat::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id, 'name' => 'Pangolin triage']);
    $sideChat->participants()->attach($owner->id, ['role' => 'owner']);
    $sideChat->messages()->create(['channel_id' => $channel->id, 'user_id' => $owner->id, 'body' => 'pangolin secrets within']);

    // The card is already on this person's screen — the list panel hands it to every member
    // of the channel. Hiding it from search would make search the one place that pretends
    // the post doesn't exist.
    expect(searchSurfaces($member, 'pangolin', 'side_chats'))->toHaveCount(1);

    // …and the conversation inside it stays shut. This is the pair that matters.
    Passport::actingAs($member);
    expect($this->getJson('/api/search?q=pangolin&type=messages')->assertOk()->json('data'))->toBeEmpty();
});

it('never returns a side chat from a channel you cannot read', function () {
    [, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id, 'is_private' => true]);
    SideChat::factory()->create(['channel_id' => $channel->id, 'name' => 'Pangolin secrets']);

    expect(searchSurfaces($member, 'pangolin', 'side_chats'))->toBeEmpty();

    $channel->allowedMembers()->attach($member->id);
    expect(searchSurfaces($member, 'pangolin', 'side_chats'))->toHaveCount(1);
});

it('carries the group a side chat is filed under', function () {
    [$owner, , $channel] = ownerWithChannel();
    $forum = SideChatForum::factory()->create(['channel_id' => $channel->id, 'name' => 'Triage']);
    SideChat::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $owner->id,
        'side_chat_forum_id' => $forum->id,
        'name' => 'Pangolin deploy plan',
    ]);

    // "Deploy plan, in Triage" locates a post the way people actually remember it.
    $rows = searchSurfaces($owner, 'pangolin', 'side_chats');

    expect($rows[0]['group_id'])->toBe($forum->id)
        ->and($rows[0]['group_name'])->toBe('Triage');
});

it('finds a side chat group by name', function () {
    [$owner, , $channel] = ownerWithChannel();
    SideChatForum::factory()->create(['channel_id' => $channel->id, 'name' => 'Pangolin triage']);

    $rows = searchSurfaces($owner, 'pangolin', 'side_chat_groups');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['kind'])->toBe('side_chat_group')
        ->and($rows[0]['channel_id'])->toBe($channel->id);
});

it('never returns a side chat group from a channel you cannot read', function () {
    [, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id, 'is_private' => true]);
    SideChatForum::factory()->create(['channel_id' => $channel->id, 'name' => 'Pangolin triage']);

    expect(searchSurfaces($member, 'pangolin', 'side_chat_groups'))->toBeEmpty();
});

it('finds a channel thread by its title', function () {
    [$owner, , $channel] = ownerWithChannel();
    $channel->threads()->create(['name' => 'Pangolin rollout', 'user_id' => $owner->id]);

    $rows = searchSurfaces($owner, 'pangolin', 'threads');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['kind'])->toBe('thread')
        ->and($rows[0]['side_chat_id'])->toBeNull();
});

it('opens a channel thread to the whole channel but a side chat thread only to its roster', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);
    $sideChat = SideChat::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id, 'name' => 'Triage']);
    $sideChat->participants()->attach($owner->id, ['role' => 'owner']);

    $channel->threads()->create(['name' => 'Pangolin rollout', 'user_id' => $owner->id]);
    $channel->threads()->create(['name' => 'Pangolin fallout', 'user_id' => $owner->id, 'side_chat_id' => $sideChat->id]);

    // A side chat's threads are reached *through* the side chat, so they inherit its roster
    // — unlike the post's own title, which is public to the channel.
    expect(collect(searchSurfaces($member, 'pangolin', 'threads'))->pluck('name')->all())
        ->toBe(['Pangolin rollout']);

    expect(collect(searchSurfaces($owner, 'pangolin', 'threads'))->pluck('name')->sort()->values()->all())
        ->toBe(['Pangolin fallout', 'Pangolin rollout']);
});

it('names the side chat a thread lives in, so the client can open both', function () {
    [$owner, , $channel] = ownerWithChannel();
    $sideChat = SideChat::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id, 'name' => 'Triage']);
    $sideChat->participants()->attach($owner->id, ['role' => 'owner']);
    $channel->threads()->create(['name' => 'Pangolin fallout', 'user_id' => $owner->id, 'side_chat_id' => $sideChat->id]);

    $rows = searchSurfaces($owner, 'pangolin', 'threads');

    expect($rows[0]['side_chat_id'])->toBe($sideChat->id)
        ->and($rows[0]['side_chat_name'])->toBe('Triage');
});

it('scopes surface searches to a channel when asked', function () {
    [$owner, $server] = ownerWithServer();
    $here = Channel::factory()->create(['server_id' => $server->id]);
    $elsewhere = Channel::factory()->create(['server_id' => $server->id]);
    SideChat::factory()->create(['channel_id' => $here->id, 'user_id' => $owner->id, 'name' => 'Pangolin here']);
    SideChat::factory()->create(['channel_id' => $elsewhere->id, 'user_id' => $owner->id, 'name' => 'Pangolin elsewhere']);

    Passport::actingAs($owner);
    $rows = $this->getJson("/api/search?q=pangolin&type=side_chats&channel_id={$here->id}")->assertOk()->json('data');

    expect(collect($rows)->pluck('name')->all())->toBe(['Pangolin here']);
});

it('offers all of it at once to the command palette', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $channel->update(['name' => 'pangolin-lab']);
    SideChat::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id, 'name' => 'Pangolin plan']);
    SideChatForum::factory()->create(['channel_id' => $channel->id, 'name' => 'Pangolin triage']);
    $channel->threads()->create(['name' => 'Pangolin rollout', 'user_id' => $owner->id]);

    Passport::actingAs($owner);
    $data = $this->getJson('/api/search?q=pangolin')->assertOk()->json('data');

    expect($data['channels'])->toHaveCount(1)
        ->and($data['side_chats'])->toHaveCount(1)
        ->and($data['threads'])->toHaveCount(1)
        ->and($data['side_chat_groups'])->toHaveCount(1);
});

it('finds surfaces in a DM as readily as in a server', function () {
    [$a, $b, $conversation] = dmBetween();
    $channel = $conversation->channel;
    SideChat::factory()->create(['channel_id' => $channel->id, 'user_id' => $a->id, 'name' => 'Pangolin plan']);

    $rows = searchSurfaces($b, 'pangolin', 'side_chats');

    // A chat's channel has no server, so the row is located by its conversation instead —
    // the client titles a DM from its members, exactly as the sidebar does.
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['server_id'])->toBeNull()
        ->and($rows[0]['conversation_id'])->toBe($conversation->id)
        ->and($rows[0]['conversation_members'])->toHaveCount(2);

    $outsider = User::factory()->create();
    expect(searchSurfaces($outsider, 'pangolin', 'side_chats'))->toBeEmpty();
});
