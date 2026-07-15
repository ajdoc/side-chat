<?php

use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use Laravel\Passport\Passport;

it('creates a thread from a message and lists it', function () {
    [$user, , $channel] = ownerWithChannel();
    $parent = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);

    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/threads", [
        'name' => 'Bug discussion',
        'message_id' => $parent->id,
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Bug discussion')
        ->assertJsonPath('data.message_id', $parent->id)
        ->assertJsonPath('data.replies_count', 0);

    $this->getJson("/api/channels/{$channel->id}/threads")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('keeps thread replies out of the main channel timeline', function () {
    [$user, , $channel] = ownerWithChannel();
    Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id, 'body' => 'main']);

    $thread = Thread::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);

    Passport::actingAs($user);

    $this->postJson("/api/threads/{$thread->id}/messages", ['body' => 'in-thread'])->assertCreated();

    $timeline = $this->getJson("/api/channels/{$channel->id}/messages")->assertOk()->json('data');
    expect(collect($timeline)->pluck('body')->all())->toBe(['main']);

    $replies = $this->getJson("/api/threads/{$thread->id}/messages")->assertOk()->json('data');
    expect(collect($replies)->pluck('body')->all())->toBe(['in-thread']);
});

// ---- Rule 2: editing a message with a thread renames the thread ----

it('renames the thread when its parent message is edited', function () {
    [$user, , $channel] = ownerWithChannel();
    $parent = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);

    $thread = Thread::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'message_id' => $parent->id,
        'name' => 'Original title',
    ]);

    Passport::actingAs($user);

    $this->patchJson("/api/messages/{$parent->id}", ['body' => 'RENAMED discussion'])->assertOk();

    expect($thread->fresh()->name)->toBe('RENAMED discussion');
});

it('exposes a thread summary and reply count on the parent message', function () {
    [$user, , $channel] = ownerWithChannel();
    $parent = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);
    $thread = Thread::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'message_id' => $parent->id,
        'name' => 'Design',
    ]);
    Message::factory()->count(2)->create([
        'channel_id' => $channel->id,
        'thread_id' => $thread->id,
        'user_id' => $user->id,
    ]);

    Passport::actingAs($user);

    $this->getJson("/api/channels/{$channel->id}/messages")
        ->assertOk()
        ->assertJsonPath('data.0.started_thread.name', 'Design')
        ->assertJsonPath('data.0.started_thread.replies_count', 2);
});

it('forbids non-members from thread endpoints', function () {
    [$owner, , $channel] = ownerWithChannel();
    $thread = Thread::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);

    Passport::actingAs(User::factory()->create());

    $this->getJson("/api/channels/{$channel->id}/threads")->assertForbidden();
    $this->getJson("/api/threads/{$thread->id}")->assertForbidden();
    $this->postJson("/api/threads/{$thread->id}/messages", ['body' => 'x'])->assertForbidden();
});
