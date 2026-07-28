<?php

use App\Models\Channel;
use App\Models\Message;
use App\Models\SideChat;
use Laravel\Passport\Passport;

/*
 * The forum layer on side chats: tags, reactions to the post, editing and deleting it.
 */

it('accepts tags when a post is created, normalised', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $res = $this->postJson("/api/channels/{$channel->id}/side-chats", [
        'name' => 'Dashboard redesign',
        // No blank entry here: TrimStrings + ConvertEmptyStringsToNull turn one into null
        // before validation sees it, so a blank tag is a 422 rather than something the
        // normaliser ever meets over HTTP. Its own blank-dropping is covered below.
        'tags' => ['Design', ' design ', 'UX'],
    ])->assertCreated();

    // Lowercased, trimmed, deduped — see SideChat::normalizeTags.
    expect($res->json('data.tags'))->toBe(['design', 'ux'])
        ->and(SideChat::normalizeTags(['  ', 'Bug', 'bug']))->toBe(['bug']);
});

it('lets the OP retitle and retag the post', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $id = $this->postJson("/api/channels/{$channel->id}/side-chats", ['name' => 'first'])
        ->assertCreated()->json('data.id');

    $this->patchJson("/api/side-chats/{$id}", ['name' => 'second', 'tags' => ['Bug']])
        ->assertOk()
        ->assertJsonPath('data.name', 'second')
        ->assertJsonPath('data.tags', ['bug']);
});

it('clears every tag when an empty list is sent', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $id = $this->postJson("/api/channels/{$channel->id}/side-chats", ['name' => 'x', 'tags' => ['bug']])
        ->assertCreated()->json('data.id');

    // The trapdoor: validation drops a parent array whose `tags.*` rule matched nothing,
    // so an empty list must not be read as "leave the tags alone".
    $this->patchJson("/api/side-chats/{$id}", ['tags' => []])
        ->assertOk()
        ->assertJsonPath('data.tags', []);
});

it('leaves tags untouched when the edit is only a title', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $id = $this->postJson("/api/channels/{$channel->id}/side-chats", ['name' => 'x', 'tags' => ['bug']])
        ->assertCreated()->json('data.id');

    $this->patchJson("/api/side-chats/{$id}", ['name' => 'y'])
        ->assertOk()
        ->assertJsonPath('data.tags', ['bug']);
});

it('refuses to let a passer-by retitle or delete the post', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);

    Passport::actingAs($owner);
    $id = $this->postJson("/api/channels/{$channel->id}/side-chats", ['name' => 'mine'])
        ->assertCreated()->json('data.id');

    Passport::actingAs($member);
    $this->patchJson("/api/side-chats/{$id}", ['name' => 'yours'])->assertForbidden();
    $this->deleteJson("/api/side-chats/{$id}")->assertForbidden();
});

it('lets an admin moderate somebody else’s post', function () {
    [$owner, $admin, $server] = twoMembers();
    $server->members()->updateExistingPivot($admin->id, ['role' => 'admin']);
    $channel = Channel::factory()->create(['server_id' => $server->id]);

    Passport::actingAs($owner);
    $id = $this->postJson("/api/channels/{$channel->id}/side-chats", ['name' => 'mine'])
        ->assertCreated()->json('data.id');

    Passport::actingAs($admin);
    $this->patchJson("/api/side-chats/{$id}", ['name' => 'moderated'])->assertOk();
    $this->deleteJson("/api/side-chats/{$id}")->assertNoContent();
});

it('toggles a reaction on the post itself', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $id = $this->postJson("/api/channels/{$channel->id}/side-chats", ['name' => 'x'])
        ->assertCreated()->json('data.id');

    $res = $this->postJson("/api/side-chats/{$id}/reactions", ['emoji' => '👍'])->assertOk();
    expect($res->json('data.reactions'))->toHaveCount(1)
        ->and($res->json('data.reactions.0.count'))->toBe(1);

    // Same emoji again takes it back.
    $res = $this->postJson("/api/side-chats/{$id}/reactions", ['emoji' => '👍'])->assertOk();
    expect($res->json('data.reactions'))->toBe([]);
});

it('lets anyone in the channel react without joining the roster', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);

    Passport::actingAs($owner);
    $id = $this->postJson("/api/channels/{$channel->id}/side-chats", ['name' => 'x'])
        ->assertCreated()->json('data.id');

    Passport::actingAs($member);
    $this->postJson("/api/side-chats/{$id}/reactions", ['emoji' => '🎉'])->assertOk();

    expect(SideChat::find($id)->hasParticipant($member))->toBeFalse();
});

it('deletes the post with its whole room, leaving the origin message standing', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $origin = $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'the topic'])
        ->assertCreated()->json('data.id');
    $id = $this->postJson("/api/channels/{$channel->id}/side-chats", ['name' => 'x', 'message_id' => $origin])
        ->assertCreated()->json('data.id');
    $this->postJson("/api/side-chats/{$id}/messages", ['body' => 'inside'])->assertCreated();
    $thread = $this->postJson("/api/side-chats/{$id}/threads", ['name' => 't'])
        ->assertCreated()->json('data.id');
    $this->postJson("/api/threads/{$thread}/messages", ['body' => 'deep inside'])->assertCreated();

    $this->deleteJson("/api/side-chats/{$id}")->assertNoContent();

    expect(SideChat::find($id))->toBeNull()
        ->and(Message::where('side_chat_id', $id)->count())->toBe(0)
        ->and(Message::where('thread_id', $thread)->count())->toBe(0)
        ->and(Message::find($origin))->not->toBeNull();
});

it('tells the client whether the asker may manage the post', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);

    Passport::actingAs($owner);
    $id = $this->postJson("/api/channels/{$channel->id}/side-chats", ['name' => 'mine'])
        ->assertCreated()->json('data.id');

    $this->getJson("/api/side-chats/{$id}")->assertJsonPath('data.can_manage', true);

    Passport::actingAs($member);
    $this->getJson("/api/side-chats/{$id}")->assertJsonPath('data.can_manage', false);
});

it('caps the number of tags a post may carry', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $id = $this->postJson("/api/channels/{$channel->id}/side-chats", ['name' => 'x'])
        ->assertCreated()->json('data.id');

    $this->patchJson("/api/side-chats/{$id}", ['tags' => array_map(fn ($i) => "t{$i}", range(1, 20))])
        ->assertStatus(422);
});

it('toggles a comment on the post itself', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $id = $this->postJson("/api/channels/{$channel->id}/side-chats", ['name' => 'x'])
        ->assertCreated()->json('data.id');

    $res = $this->postJson("/api/side-chats/{$id}/comments", ['body' => 'Looks good', 'emoji' => '✅'])->assertOk();
    expect($res->json('data.comments'))->toHaveCount(1)
        ->and($res->json('data.comments.0.body'))->toBe('Looks good')
        ->and($res->json('data.comments.0.count'))->toBe(1);

    // The same phrase again takes the co-sign back — a chip is a toggle.
    $res = $this->postJson("/api/side-chats/{$id}/comments", ['body' => 'looks good ', 'emoji' => '✅'])->assertOk();
    expect($res->json('data.comments'))->toBe([]);
});

it('groups a post comment by normalised phrase, like a message comment', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);

    Passport::actingAs($owner);
    $id = $this->postJson("/api/channels/{$channel->id}/side-chats", ['name' => 'x'])
        ->assertCreated()->json('data.id');
    $this->postJson("/api/side-chats/{$id}/comments", ['body' => 'Ship it'])->assertOk();

    Passport::actingAs($member);
    $res = $this->postJson("/api/side-chats/{$id}/comments", ['body' => 'ship it'])->assertOk();

    // One chip, two co-signers — the casing difference must not fork it.
    expect($res->json('data.comments'))->toHaveCount(1)
        ->and($res->json('data.comments.0.count'))->toBe(2);
});

it('lets anyone in the channel comment without joining the roster', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);

    Passport::actingAs($owner);
    $id = $this->postJson("/api/channels/{$channel->id}/side-chats", ['name' => 'x'])
        ->assertCreated()->json('data.id');

    Passport::actingAs($member);
    $this->postJson("/api/side-chats/{$id}/comments", ['body' => 'Nice'])->assertOk();

    expect(SideChat::find($id)->hasParticipant($member))->toBeFalse();
});

it('lets only the author remove one of their post comments', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);

    Passport::actingAs($owner);
    $id = $this->postJson("/api/channels/{$channel->id}/side-chats", ['name' => 'x'])
        ->assertCreated()->json('data.id');
    $this->postJson("/api/side-chats/{$id}/comments", ['body' => 'Mine'])->assertOk();
    $commentId = $this->getJson("/api/side-chats/{$id}/comments")->assertOk()->json('data.0.id');

    Passport::actingAs($member);
    $this->deleteJson("/api/side-chat-comments/{$commentId}")->assertForbidden();

    Passport::actingAs($owner);
    $this->deleteJson("/api/side-chat-comments/{$commentId}")->assertOk()
        ->assertJsonPath('data.comments', []);
});

it('marks a reply aimed at the post itself', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $id = $this->postJson("/api/channels/{$channel->id}/side-chats", ['name' => 'x'])
        ->assertCreated()->json('data.id');

    $this->postJson("/api/side-chats/{$id}/messages", ['body' => 'top-level', 'replies_to_post' => true])
        ->assertCreated()
        ->assertJsonPath('data.replies_to_post', true);

    // An ordinary message in the same side chat is not marked.
    $this->postJson("/api/side-chats/{$id}/messages", ['body' => 'just talking'])
        ->assertCreated()
        ->assertJsonPath('data.replies_to_post', false);
});

it('refuses a reply that targets both the post and a message', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);
    $id = $this->postJson("/api/channels/{$channel->id}/side-chats", ['name' => 'x'])
        ->assertCreated()->json('data.id');
    $inside = $this->postJson("/api/side-chats/{$id}/messages", ['body' => 'first'])
        ->assertCreated()->json('data.id');

    // The chip would have to name two different things at once.
    $this->postJson("/api/side-chats/{$id}/messages", [
        'body' => 'both?',
        'reply_to_id' => $inside,
        'replies_to_post' => true,
    ])->assertStatus(422);
});

it('still refuses a post reply from someone off the roster', function () {
    [$owner, $member, $server] = twoMembers();
    $channel = Channel::factory()->create(['server_id' => $server->id]);

    Passport::actingAs($owner);
    $id = $this->postJson("/api/channels/{$channel->id}/side-chats", ['name' => 'x'])
        ->assertCreated()->json('data.id');

    // Reacting and commenting are open to the channel; *posting* is what joining buys.
    Passport::actingAs($member);
    $this->postJson("/api/side-chats/{$id}/messages", ['body' => 'hi', 'replies_to_post' => true])
        ->assertForbidden();
});
