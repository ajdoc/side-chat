<?php

use App\Events\ChannelCreated;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;

it('gives every new channel a General discussion, and hangs the map off it', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/channels", [
        'name' => 'the-office',
        'type' => 'space',
        'preset' => 'office',
    ])->assertCreated();

    $channel = Channel::where('name', 'the-office')->sole();
    $general = $channel->discussions()->sole();

    expect($channel->isContainer())->toBeTrue()
        ->and($general->isDiscussion())->toBeTrue()
        ->and($general->name)->toBe('General')
        // A discussion is the same kind of thing as the channel it hangs under, which is what
        // lets it own a call or a map of its own.
        ->and($general->type)->toBe('space')
        ->and($general->spaceMap)->not->toBeNull()
        // ...and the container owns no room at all.
        ->and($channel->spaceMap)->toBeNull();
});

it('lists discussions nested under their channel rather than beside it', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    Channel::factory()->discussionOf($channel)->create(['name' => 'off-topic']);

    Passport::actingAs($owner);

    $response = $this->getJson("/api/servers/{$server->id}/channels")->assertOk();

    // One row for the channel — not three rows for the channel and its two discussions.
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.parent_id'))->toBeNull()
        ->and($response->json('data.0.discussions'))->toHaveCount(2)
        ->and($response->json('data.0.discussions.1.parent_id'))->toBe($channel->id);
});

it('badges a channel with the unread total of all its discussions', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    $general = $channel->discussions()->sole();
    $offTopic = Channel::factory()->discussionOf($channel)->create(['name' => 'off-topic']);

    Message::factory()->count(2)->create(['channel_id' => $general->id, 'user_id' => $owner->id]);
    Message::factory()->count(3)->create(['channel_id' => $offTopic->id, 'user_id' => $owner->id]);

    Passport::actingAs($member);

    $response = $this->getJson("/api/servers/{$server->id}/channels")->assertOk();

    expect($response->json('data.0.unread_count'))->toBe(5)
        ->and($response->json('data.0.discussions.0.unread_count'))->toBe(2)
        ->and($response->json('data.0.discussions.1.unread_count'))->toBe(3);
});

it('hides a private channel’s discussions from everyone it hides itself from', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $outsider = User::factory()->create();
    $server->members()->attach($outsider->id, ['role' => 'member']);

    $general = $channel->discussions()->sole();
    $channel->update(['is_private' => true]);

    // The lock is on the container, and the discussion is not itself private — so this is
    // exactly the case where forgetting to consult the parent would leak the whole timeline.
    expect($general->is_private)->toBeFalse()
        ->and($general->hasMember($outsider))->toBeFalse()
        ->and($general->hasMember($owner))->toBeTrue()
        ->and(Channel::visibleTo($outsider)->whereKey($general->id)->exists())->toBeFalse()
        ->and(Channel::visibleTo($owner)->whereKey($general->id)->exists())->toBeTrue();
});

it('hides a private discussion inside a channel everyone can see', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    $secret = Channel::factory()->discussionOf($channel)->create([
        'name' => 'mods-only',
        'is_private' => true,
    ]);

    Passport::actingAs($member);

    $response = $this->getJson("/api/servers/{$server->id}/channels")->assertOk();

    expect($response->json('data.0.discussions'))->toHaveCount(1)
        ->and($response->json('data.0.discussions.0.name'))->toBe('General')
        ->and($secret->hasMember($member))->toBeFalse()
        // Staff set the lock, so staff keep the key.
        ->and($secret->hasMember($owner))->toBeTrue();
});

it('adds a discussion to a channel, and lets anyone in the server do it by default', function () {
    [, $server, $channel] = ownerWithChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    Passport::actingAs($member);

    $this->postJson("/api/channels/{$channel->id}/discussions", ['name' => 'off-topic'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'off-topic')
        ->assertJsonPath('data.parent_id', $channel->id)
        ->assertJsonPath('data.type', 'text');

    expect($channel->discussions()->count())->toBe(2);
});

it('narrows discussion creation to staff when the server says so', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);
    $server->update(['discussion_creation' => 'staff']);

    Passport::actingAs($member);
    $this->postJson("/api/channels/{$channel->id}/discussions", ['name' => 'off-topic'])->assertForbidden();

    Passport::actingAs($owner);
    $this->postJson("/api/channels/{$channel->id}/discussions", ['name' => 'off-topic'])->assertCreated();
});

it('refuses to nest a discussion inside a discussion', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->discussions()->sole()->id}/discussions", ['name' => 'deeper'])
        ->assertJsonValidationErrors('channel');
});

it('starts a new Side Space discussion as a copy of an existing room', function () {
    [$owner, $server] = ownerWithServer();
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/channels", [
        'name' => 'the-office',
        'type' => 'space',
        'preset' => 'office',
    ])->assertCreated();

    $channel = Channel::where('name', 'the-office')->sole();
    $general = $channel->discussions()->sole();

    $this->postJson("/api/channels/{$channel->id}/discussions", ['name' => 'the-annex'])->assertCreated();

    $annex = $channel->discussions()->where('name', 'the-annex')->sole();

    // The room you already decorated, not a blank grid — a second discussion is somewhere else
    // to talk in the same place.
    expect($annex->spaceMap)->not->toBeNull()
        ->and($annex->spaceMap->tiles)->toEqual($general->spaceMap->tiles)
        ->and($annex->spaceMap->objects)->toEqual($general->spaceMap->objects)
        // ...but its own map, not a second name for the first.
        ->and($annex->spaceMap->id)->not->toBe($general->spaceMap->id);
});

it('never leaves a channel with no discussions', function () {
    [$owner, , $channel] = ownerWithChannel();
    $general = $channel->discussions()->sole();
    $second = Channel::factory()->discussionOf($channel)->create(['name' => 'off-topic']);

    Passport::actingAs($owner);

    $this->deleteJson("/api/discussions/{$second->id}")->assertNoContent();
    // ...and now General is the last one standing.
    $this->deleteJson("/api/discussions/{$general->id}")->assertJsonValidationErrors('channel');

    expect($channel->discussions()->count())->toBe(1);
});

it('keeps deletion with staff, not with whoever opened the discussion', function () {
    [, $server, $channel] = ownerWithChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    Passport::actingAs($member);
    $created = $this->postJson("/api/channels/{$channel->id}/discussions", ['name' => 'off-topic'])
        ->assertCreated()->json('data.id');

    $this->deleteJson("/api/discussions/{$created}")->assertForbidden();
});

it('remembers which discussion a channel opens on, for one person only', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);
    $offTopic = Channel::factory()->discussionOf($channel)->create(['name' => 'off-topic']);

    Passport::actingAs($member);
    $this->putJson("/api/discussions/{$offTopic->id}/default")->assertNoContent();

    expect($this->getJson("/api/servers/{$server->id}/channels")->json('data.0.default_child_id'))
        ->toBe($offTopic->id);

    // Nobody else's channel moved.
    Passport::actingAs($owner);
    expect($this->getJson("/api/servers/{$server->id}/channels")->json('data.0.default_child_id'))
        ->toBeNull();

    Passport::actingAs($member);
    $this->deleteJson("/api/discussions/{$offTopic->id}/default")->assertNoContent();
    expect($this->getJson("/api/servers/{$server->id}/channels")->json('data.0.default_child_id'))
        ->toBeNull();
});

it('announces a new channel and a new discussion without naming either', function () {
    Event::fake([ChannelCreated::class]);

    [$owner, $server, $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/servers/{$server->id}/channels", ['name' => 'ideas', 'type' => 'text'])
        ->assertCreated();
    $this->postJson("/api/channels/{$channel->id}/discussions", ['name' => 'off-topic'])
        ->assertCreated();

    Event::assertDispatchedTimes(ChannelCreated::class, 2);

    // Ids only. A discussion can live inside a private channel, so a payload carrying its name
    // would tell the whole server that a room they can't see exists and what it's called.
    Event::assertDispatched(ChannelCreated::class, function (ChannelCreated $event) use ($channel) {
        $payload = $event->broadcastWith();

        return $payload['parent_id'] === $channel->id
            && array_keys($payload) === ['channel_id', 'parent_id', 'server_id'];
    });
});

it('lists a channel’s discussions with what has been said in each', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $general = $channel->discussions()->sole();
    $quiet = Channel::factory()->discussionOf($channel)->create(['name' => 'quiet-corner']);

    Message::factory()->count(3)->create(['channel_id' => $general->id, 'user_id' => $owner->id]);
    // None of these are things somebody said, and none should be counted.
    Message::factory()->create(['channel_id' => $general->id, 'user_id' => $owner->id, 'type' => 'system']);
    Message::factory()->create([
        'channel_id' => $general->id,
        'user_id' => $owner->id,
        'thread_id' => Thread::factory()->create(['channel_id' => $general->id])->id,
    ]);

    Passport::actingAs($owner);

    $rows = collect($this->getJson("/api/channels/{$channel->id}/discussions")->assertOk()->json('data'));

    expect($rows)->toHaveCount(2)
        // Newest activity first: the quiet one has none, so it sorts by when it was made.
        ->and($rows->firstWhere('name', 'General')['message_count'])->toBe(3)
        ->and($rows->firstWhere('name', 'General')['last_message_at'])->not->toBeNull()
        ->and($rows->firstWhere('name', 'quiet-corner')['message_count'])->toBe(0)
        ->and($rows->firstWhere('name', 'quiet-corner')['last_message_at'])->toBeNull();

    // Searching narrows by name, and sorting is honoured.
    $names = collect($this->getJson("/api/channels/{$channel->id}/discussions?q=quiet")->json('data'))->pluck('name');
    expect($names->all())->toBe(['quiet-corner']);

    $byName = collect($this->getJson("/api/channels/{$channel->id}/discussions?sort=name")->json('data'))->pluck('name');
    expect($byName->all())->toBe(['General', 'quiet-corner']);
});

it('keeps a private discussion out of the directory', function () {
    [, $server, $channel] = ownerWithChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);
    Channel::factory()->discussionOf($channel)->create(['name' => 'mods-only', 'is_private' => true]);

    Passport::actingAs($member);

    $names = collect($this->getJson("/api/channels/{$channel->id}/discussions")->assertOk()->json('data'))->pluck('name');

    expect($names->all())->toBe(['General']);
});
