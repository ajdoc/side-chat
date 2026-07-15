<?php

use App\Events\ConversationCreated;
use App\Events\ConversationRemoved;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;

it('opens a dm and gives it a channel to live in', function () {
    [$me, $them] = twoMembers();

    Passport::actingAs($me);

    $response = $this->postJson('/api/conversations/dm', ['user_id' => $them->id])->assertOk();

    expect($response->json('data.type'))->toBe('dm')
        ->and($response->json('data.name'))->toBeNull()
        ->and($response->json('data.members'))->toHaveCount(2)
        // The whole design in one assertion: a chat is addressed by a channel id, which is
        // why every message/thread/pin/reaction/call endpoint already works in it.
        ->and($response->json('data.channel_id'))->not->toBeNull();
});

it('hands back the same dm instead of opening a second one', function () {
    [$me, $them] = twoMembers();

    Passport::actingAs($me);
    $first = $this->postJson('/api/conversations/dm', ['user_id' => $them->id])->assertOk();

    // The other person clicking "message" from their side must land in the same history.
    Passport::actingAs($them);
    $second = $this->postJson('/api/conversations/dm', ['user_id' => $me->id])->assertOk();

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect(Conversation::count())->toBe(1);
});

it('refuses to dm someone you share no server with', function () {
    [$me] = ownerWithServer();
    $stranger = User::factory()->create();

    Passport::actingAs($me);

    $this->postJson('/api/conversations/dm', ['user_id' => $stranger->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_id');

    expect(Conversation::count())->toBe(0);
});

it('tells the other person about a dm they have never heard of', function () {
    Event::fake([ConversationCreated::class]);

    [$me, $them] = twoMembers();

    Passport::actingAs($me);
    $this->postJson('/api/conversations/dm', ['user_id' => $them->id])->assertOk();

    // Not on the conversation's own stream — they aren't subscribed to a conversation that
    // did not exist a moment ago. It has to reach them personally.
    Event::assertDispatched(ConversationCreated::class, function (ConversationCreated $event) use ($me, $them) {
        $names = collect($event->broadcastOn())->map->name->all();

        return in_array('private-user.'.$them->id, $names, true)
            && in_array('private-user.'.$me->id, $names, true);
    });
});

it('starts a group chat owned by whoever made it', function () {
    [$me, $them] = twoMembers();

    Passport::actingAs($me);

    $response = $this->postJson('/api/conversations/group', [
        'name' => 'Design crew',
        'user_ids' => [$them->id],
    ])->assertCreated();

    expect($response->json('data.type'))->toBe('group')
        ->and($response->json('data.name'))->toBe('Design crew')
        ->and($response->json('data.owner_id'))->toBe($me->id)
        ->and($response->json('data.members'))->toHaveCount(2);
});

it('refuses to build a group out of strangers', function () {
    [$me] = ownerWithServer();
    $stranger = User::factory()->create();

    Passport::actingAs($me);

    $this->postJson('/api/conversations/group', [
        'name' => 'Nope',
        'user_ids' => [$stranger->id],
    ])->assertStatus(422)->assertJsonValidationErrors('user_ids');
});

it('lists your chats, most recently spoken in first', function () {
    [$me, $them] = twoMembers();

    $quiet = Conversation::factory()->group('Quiet')->withMembers([$me, $them])->create();
    $loud = Conversation::factory()->group('Loud')->withMembers([$me, $them])->create();

    Message::factory()->create([
        'channel_id' => $loud->channel->id,
        'user_id' => $them->id,
    ]);

    Passport::actingAs($me);

    $response = $this->getJson('/api/conversations')->assertOk();

    expect($response->json('data.0.id'))->toBe($loud->id)
        ->and($response->json('data.1.id'))->toBe($quiet->id);
});

it('badges a chat with what you have not read', function () {
    [$me, $them, $dm] = dmBetween();

    Message::factory()->count(3)->create([
        'channel_id' => $dm->channel->id,
        'user_id' => $them->id,
    ]);

    Passport::actingAs($me);

    $response = $this->getJson('/api/conversations')->assertOk();

    expect($response->json('data.0.unread_count'))->toBe(3);
});

it('sends a chat’s unread ping to each member personally', function () {
    Event::fake([App\Events\ChannelActivity::class]);

    [$me, $them, $dm] = dmBetween();

    Passport::actingAs($them);
    $this->postJson("/api/channels/{$dm->channel->id}/messages", ['body' => 'you there?'])
        ->assertCreated();

    // Not to `conversation.{id}` — I'm not subscribed to a chat I haven't opened, and a
    // chat I haven't opened is exactly the one that needs the badge. It has to find me
    // wherever I am, which means my own stream.
    Event::assertDispatched(App\Events\ChannelActivity::class, function ($event) use ($me, $them) {
        $names = collect($event->broadcastOn())->map->name->all();

        return in_array('private-user.'.$me->id, $names, true)
            && in_array('private-user.'.$them->id, $names, true);
    });
});

it('still sends a server’s unread ping to the one stream everybody holds open', function () {
    Event::fake([App\Events\ChannelActivity::class]);

    [$user, $server, $channel] = ownerWithChannel();

    Passport::actingAs($user);
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'hi'])->assertCreated();

    // A server *does* have such a stream, so it stays one broadcast rather than N.
    Event::assertDispatched(App\Events\ChannelActivity::class, function ($event) use ($server) {
        return collect($event->broadcastOn())->map->name->all() === ['private-server.'.$server->id];
    });
});

it('keeps other people’s chats out of your list', function () {
    [$me, $them] = twoMembers();
    $third = User::factory()->create();

    Conversation::factory()->withMembers([$them, $third])->create();

    Passport::actingAs($me);

    expect($this->getJson('/api/conversations')->assertOk()->json('data'))->toBeEmpty();
});

it('forbids reading a chat you are not in', function () {
    [, $them, $dm] = dmBetween();
    $outsider = User::factory()->create();

    Passport::actingAs($outsider);

    $this->getJson("/api/conversations/{$dm->id}")->assertForbidden();
    // …and the same goes for the channel underneath it, which is the one that matters:
    // that's where the messages actually are.
    $this->getJson("/api/channels/{$dm->channel->id}/messages")->assertForbidden();
});

it('lets the two people in a dm use the whole message stack', function () {
    [$me, , $dm] = dmBetween();

    Passport::actingAs($me);

    // Not one line of this was written for conversations. It works because a chat is a
    // channel, and all of it is addressed by channel id.
    $sent = $this->postJson("/api/channels/{$dm->channel->id}/messages", ['body' => 'hey'])
        ->assertCreated();

    $id = $sent->json('data.id');

    $this->postJson("/api/messages/{$id}/reactions", ['emoji' => '👋'])->assertOk();
    $this->postJson("/api/messages/{$id}/pin")->assertOk();
    $this->postJson("/api/channels/{$dm->channel->id}/threads", [
        'message_id' => $id,
        'name' => 'a thread in a dm',
    ])->assertCreated();

    $this->getJson("/api/channels/{$dm->channel->id}/messages")
        ->assertOk()
        ->assertJsonPath('data.0.body', 'hey');
});

it('renames a group, but only for the person who made it', function () {
    [$me, $them] = twoMembers();
    $group = Conversation::factory()->group('Old')->withMembers([$me, $them])->create();
    $group->update(['owner_id' => $me->id]);

    Passport::actingAs($them);
    $this->patchJson("/api/conversations/{$group->id}", ['name' => 'Hijacked'])
        ->assertStatus(422);

    Passport::actingAs($me);
    $this->patchJson("/api/conversations/{$group->id}", ['name' => 'New'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New');
});

it('refuses to rename a dm — it is named after whoever you are talking to', function () {
    [$me, , $dm] = dmBetween();

    Passport::actingAs($me);

    $this->patchJson("/api/conversations/{$dm->id}", ['name' => 'Nope'])->assertStatus(422);
});

it('adds people to a group and says so in the transcript', function () {
    [$me, $them, $server] = twoMembers();
    $third = User::factory()->create();
    $server->members()->attach($third->id, ['role' => 'member']);

    $group = Conversation::factory()->group('Crew')->withMembers([$me, $them])->create();

    Passport::actingAs($me);

    $this->postJson("/api/conversations/{$group->id}/members", ['user_ids' => [$third->id]])
        ->assertOk()
        ->assertJsonPath('data.0.id', $third->id);

    expect($group->fresh()->memberIds())->toContain($third->id);

    $system = Message::where('channel_id', $group->channel->id)->where('type', 'system')->first();
    expect($system->body)->toContain('added')->toContain($third->name);
});

it('refuses to add a third person to a dm', function () {
    [$me, , $dm] = dmBetween();
    $third = User::factory()->create();

    Passport::actingAs($me);

    // A DM is *the* conversation between two people — the uniqueness that guarantees has no
    // meaning for three. Wanting a third person means wanting a group.
    $this->postJson("/api/conversations/{$dm->id}/members", ['user_ids' => [$third->id]])
        ->assertStatus(422);
});

it('leaves a group and tells the leaver’s own tabs', function () {
    Event::fake([ConversationRemoved::class, ConversationUpdated::class]);

    [$me, $them] = twoMembers();
    $group = Conversation::factory()->group('Crew')->withMembers([$me, $them])->create();

    Passport::actingAs($them);

    $this->postJson("/api/conversations/{$group->id}/leave")->assertNoContent();

    expect($group->fresh()->memberIds())->not->toContain($them->id);

    // They're no longer a member, so the conversation stream can no longer reach them —
    // which is exactly the person whose sidebar needs to drop the row.
    Event::assertDispatched(
        ConversationRemoved::class,
        fn (ConversationRemoved $e) => $e->userId === $them->id && $e->conversationId === $group->id,
    );
});

it('hands a group on to someone else when its owner walks out', function () {
    [$me, $them] = twoMembers();
    $group = Conversation::factory()->group('Crew')->withMembers([$me, $them])->create();
    $group->update(['owner_id' => $me->id]);

    Passport::actingAs($me);
    $this->postJson("/api/conversations/{$group->id}/leave")->assertNoContent();

    // Otherwise the group is un-renameable forever, and it still belongs to the people in it.
    expect($group->fresh()->owner_id)->toBe($them->id);
});

it('refuses to let you leave a dm', function () {
    [$me, , $dm] = dmBetween();

    Passport::actingAs($me);

    $this->postJson("/api/conversations/{$dm->id}/leave")->assertStatus(422);
});

it('offers you the people you share a server with, and nobody else', function () {
    [$me, $them] = twoMembers();
    $stranger = User::factory()->create(['name' => 'Nobody Incommon']);

    Passport::actingAs($me);

    $contacts = $this->getJson('/api/conversations/contacts')->assertOk()->json('data');
    $ids = collect($contacts)->pluck('id')->all();

    expect($ids)->toContain($them->id)
        ->not->toContain($stranger->id)
        ->not->toContain($me->id); // you are not your own contact
});

it('refuses to delete the channel a chat lives in', function () {
    [$me, , $dm] = dmBetween();

    Passport::actingAs($me);

    // The owner-only channel endpoints resolve a *server*, and a chat has none — so they
    // decline rather than letting someone delete the only room a DM has.
    $this->deleteJson("/api/channels/{$dm->channel->id}")->assertForbidden();
    $this->patchJson("/api/channels/{$dm->channel->id}", ['name' => 'nope'])->assertForbidden();
});
