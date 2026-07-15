<?php

use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use Laravel\Passport\Passport;

it('pins and unpins a message with the same endpoint', function () {
    [$owner, , $channel] = ownerWithChannel();
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);

    Passport::actingAs($owner);

    $this->postJson("/api/messages/{$message->id}/pin")
        ->assertOk()
        ->assertJsonPath('data.pinned', true);

    expect($message->fresh()->pinned_by)->toBe($owner->id);

    // Same call again takes it back.
    $this->postJson("/api/messages/{$message->id}/pin")
        ->assertOk()
        ->assertJsonPath('data.pinned', false);

    expect($message->fresh()->pinned_at)->toBeNull()
        ->and($message->fresh()->pinned_by)->toBeNull();
});

it('lists a channels pinned messages, newest pin first', function () {
    [$owner, , $channel] = ownerWithChannel();
    $old = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);
    $new = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);
    Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]); // unpinned

    Passport::actingAs($owner);
    $this->postJson("/api/messages/{$old->id}/pin")->assertOk();
    $this->travel(1)->minute();
    $this->postJson("/api/messages/{$new->id}/pin")->assertOk();

    $this->getJson("/api/channels/{$channel->id}/pins")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        // Ordered by when it was *pinned*, not when it was written.
        ->assertJsonPath('data.0.id', $new->id)
        ->assertJsonPath('data.1.id', $old->id)
        ->assertJsonPath('data.0.pinned_by', $owner->name);
});

it('lets any member unpin what someone else pinned', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);

    Passport::actingAs($owner);
    $this->postJson("/api/messages/{$message->id}/pin")->assertOk();

    // A pin is a statement about the channel, not about the person who made it.
    Passport::actingAs($member);
    $this->postJson("/api/messages/{$message->id}/pin")
        ->assertOk()
        ->assertJsonPath('data.pinned', false);
});

it('pins a message that lives in a thread and still lists it under the channel', function () {
    [$owner, , $channel] = ownerWithChannel();
    $thread = Thread::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);
    $reply = Message::factory()->create([
        'channel_id' => $channel->id,
        'thread_id' => $thread->id,
        'user_id' => $owner->id,
    ]);

    Passport::actingAs($owner);
    $this->postJson("/api/messages/{$reply->id}/pin")->assertOk();

    // The Pinned tab belongs to the channel, and a thread boundary doesn't hide a pin
    // from it — the row carries thread_id so the UI knows it can't jump to it.
    $this->getJson("/api/channels/{$channel->id}/pins")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $reply->id)
        ->assertJsonPath('data.0.thread_id', $thread->id);
});

it('refuses to pin a system message', function () {
    [$owner, , $channel] = ownerWithChannel();
    $system = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $owner->id,
        'type' => 'system',
    ]);

    Passport::actingAs($owner);
    $this->postJson("/api/messages/{$system->id}/pin")
        ->assertStatus(422)
        ->assertJsonValidationErrors('message');
});

it('unpins nothing when the message is deleted', function () {
    [$owner, , $channel] = ownerWithChannel();
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);

    Passport::actingAs($owner);
    $this->postJson("/api/messages/{$message->id}/pin")->assertOk();
    $this->deleteJson("/api/messages/{$message->id}")->assertNoContent();

    $this->getJson("/api/channels/{$channel->id}/pins")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('forbids non-members from pinning or listing pins', function () {
    [$owner, , $channel] = ownerWithChannel();
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);

    Passport::actingAs(User::factory()->create());

    $this->postJson("/api/messages/{$message->id}/pin")->assertForbidden();
    $this->getJson("/api/channels/{$channel->id}/pins")->assertForbidden();
});
