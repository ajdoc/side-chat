<?php

use App\Models\Channel;
use App\Models\Server;
use App\Models\SideChat;
use App\Models\SideChatForum;
use App\Models\User;
use Laravel\Passport\Passport;

/*
 * Forum *groups* — the named headings a channel's side chats are filed under.
 *
 * Distinct from the tag layer in SideChatForumTest, and the tests are separate for the same
 * reason the features are: a tag describes a post, a group arranges the list.
 */

/** A member of the owner's server who is not staff — the "anyone else" of these tests. */
function forumMember(Server $server): User
{
    $user = User::factory()->create();
    $server->members()->attach($user->id, ['role' => 'member']);

    return $user;
}

it('lets staff create groups, newest at the bottom', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/side-chat-forums", ['name' => 'Announcements'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Announcements');

    $second = $this->postJson("/api/channels/{$channel->id}/side-chat-forums", ['name' => 'Bugs'])
        ->assertCreated();

    // A new group lands after the ones already there rather than rearranging them.
    expect($second->json('data.position'))->toBeGreaterThan(0);

    $this->getJson("/api/channels/{$channel->id}/side-chat-forums")
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Announcements')
        ->assertJsonPath('data.1.name', 'Bugs');
});

it('refuses two groups with the same name in one channel', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/side-chat-forums", ['name' => 'Bugs'])->assertCreated();
    $this->postJson("/api/channels/{$channel->id}/side-chat-forums", ['name' => 'Bugs'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('lets an ordinary member read the groups but not change them', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $forumId = $this->postJson("/api/channels/{$channel->id}/side-chat-forums", ['name' => 'Bugs'])
        ->assertCreated()->json('data.id');

    Passport::actingAs(forumMember($server));

    // Reading is membership; the heading is part of the list everyone reads. The permission
    // rides on `meta` rather than each row — it's a fact about the channel, not the heading.
    $this->getJson("/api/channels/{$channel->id}/side-chat-forums")
        ->assertOk()
        ->assertJsonPath('meta.can_manage', false)
        ->assertJsonCount(1, 'data');

    $this->postJson("/api/channels/{$channel->id}/side-chat-forums", ['name' => 'Mine'])->assertForbidden();
    $this->patchJson("/api/side-chat-forums/{$forumId}", ['name' => 'Theirs'])->assertForbidden();
    $this->deleteJson("/api/side-chat-forums/{$forumId}")->assertForbidden();
});

it('keeps a non-member out of the group list entirely', function () {
    [, , $channel] = ownerWithChannel();
    Passport::actingAs(User::factory()->create());

    $this->getJson("/api/channels/{$channel->id}/side-chat-forums")->assertForbidden();
});

it('files a post into a group at creation and moves it afterwards', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $forumId = $this->postJson("/api/channels/{$channel->id}/side-chat-forums", ['name' => 'Bugs'])
        ->assertCreated()->json('data.id');

    $postId = $this->postJson("/api/channels/{$channel->id}/side-chats", [
        'name' => 'Login is broken',
        'side_chat_forum_id' => $forumId,
    ])->assertCreated()->assertJsonPath('data.side_chat_forum_id', $forumId)->json('data.id');

    // Null is a value here, not an omission: it means "back to Uncategorised".
    $this->patchJson("/api/side-chats/{$postId}", ['side_chat_forum_id' => null])
        ->assertOk()
        ->assertJsonPath('data.side_chat_forum_id', null);
});

it('leaves the group alone when the edit is only a title', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $forumId = $this->postJson("/api/channels/{$channel->id}/side-chat-forums", ['name' => 'Bugs'])
        ->assertCreated()->json('data.id');
    $postId = $this->postJson("/api/channels/{$channel->id}/side-chats", [
        'name' => 'x',
        'side_chat_forum_id' => $forumId,
    ])->assertCreated()->json('data.id');

    $this->patchJson("/api/side-chats/{$postId}", ['name' => 'y'])
        ->assertOk()
        ->assertJsonPath('data.side_chat_forum_id', $forumId);
});

it('refuses a group belonging to another channel', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $other = Channel::factory()->create(['server_id' => $server->id]);
    $foreign = SideChatForum::factory()->create(['channel_id' => $other->id]);

    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/side-chats", [
        'name' => 'x',
        'side_chat_forum_id' => $foreign->id,
    ])->assertStatus(422)->assertJsonValidationErrors('side_chat_forum_id');
});

it('returns a deleted group’s posts to Uncategorised rather than deleting them', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $forumId = $this->postJson("/api/channels/{$channel->id}/side-chat-forums", ['name' => 'Bugs'])
        ->assertCreated()->json('data.id');
    $postId = $this->postJson("/api/channels/{$channel->id}/side-chats", [
        'name' => 'Login is broken',
        'side_chat_forum_id' => $forumId,
    ])->assertCreated()->json('data.id');

    $this->deleteJson("/api/side-chat-forums/{$forumId}")->assertNoContent();

    // The whole point of nullOnDelete: tidying the list must never delete conversations.
    $post = SideChat::find($postId);
    expect($post)->not->toBeNull()
        ->and($post->side_chat_forum_id)->toBeNull();
});

it('sets the whole running order in one call', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $ids = collect(['A', 'B', 'C'])->map(
        fn ($name) => $this->postJson("/api/channels/{$channel->id}/side-chat-forums", ['name' => $name])
            ->assertCreated()->json('data.id')
    )->all();

    $this->putJson("/api/channels/{$channel->id}/side-chat-forums/order", ['ids' => array_reverse($ids)])
        ->assertOk()
        ->assertJsonPath('data.0.name', 'C')
        ->assertJsonPath('data.2.name', 'A');
});
