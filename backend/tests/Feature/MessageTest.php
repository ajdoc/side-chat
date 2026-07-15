<?php

use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use Laravel\Passport\Passport;

it('returns the latest 200 messages with has_more, and walks back with ?before', function () {
    [$user, , $channel] = ownerWithChannel();

    foreach (range(1, 205) as $i) {
        Message::factory()->create([
            'channel_id' => $channel->id,
            'user_id' => $user->id,
            'body' => "m{$i}",
        ]);
    }

    Passport::actingAs($user);

    $first = $this->getJson("/api/channels/{$channel->id}/messages")->assertOk();

    expect($first->json('data'))->toHaveCount(200)
        ->and($first->json('has_more'))->toBeTrue()
        ->and($first->json('data.0.body'))->toBe('m6')      // chronological
        ->and($first->json('data.199.body'))->toBe('m205');

    $oldest = $first->json('data.0.id');

    $second = $this->getJson("/api/channels/{$channel->id}/messages?before={$oldest}")->assertOk();

    expect($second->json('data'))->toHaveCount(5)
        ->and($second->json('has_more'))->toBeFalse()
        ->and($second->json('data.0.body'))->toBe('m1');
});

it('posts a message and an inline reply', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $parent = $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'question?'])
        ->assertCreated()->json('data.id');

    $this->postJson("/api/channels/{$channel->id}/messages", [
        'body' => 'answer',
        'reply_to_id' => $parent,
    ])->assertCreated()
        ->assertJsonPath('data.reply_to.id', $parent)
        ->assertJsonPath('data.reply_to.body', 'question?');
});

it('validates the message body', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => ''])
        ->assertStatus(422)->assertJsonValidationErrors('body');
});

it('forbids non-members from reading or posting', function () {
    [, , $channel] = ownerWithChannel();
    Passport::actingAs(User::factory()->create());

    $this->getJson("/api/channels/{$channel->id}/messages")->assertForbidden();
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'hi'])->assertForbidden();
});

// ---- Rule 1: only the sender may edit/delete ----

it('lets the sender edit their message and flags it as edited', function () {
    [$user, , $channel] = ownerWithChannel();
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);

    Passport::actingAs($user);

    $this->patchJson("/api/messages/{$message->id}", ['body' => 'edited!'])
        ->assertOk()
        ->assertJsonPath('data.body', 'edited!')
        ->assertJsonPath('data.edited', true);
});

it('forbids editing or deleting someone else message', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);

    // Even a fellow member of the same server cannot touch it.
    $other = User::factory()->create();
    $server->members()->attach($other->id, ['role' => 'member']);

    Passport::actingAs($other);

    $this->patchJson("/api/messages/{$message->id}", ['body' => 'hax'])->assertForbidden();
    $this->deleteJson("/api/messages/{$message->id}")->assertForbidden();

    expect(Message::find($message->id))->not->toBeNull();
});

it('lets the sender delete their message', function () {
    [$user, , $channel] = ownerWithChannel();
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);

    Passport::actingAs($user);

    $this->deleteJson("/api/messages/{$message->id}")->assertNoContent();

    expect(Message::find($message->id))->toBeNull();
});

// ---- Rule 3: deleting a message with a thread cascades ----

it('deletes the thread and all its replies when the parent message is deleted', function () {
    [$user, , $channel] = ownerWithChannel();
    $parent = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);

    $thread = Thread::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'message_id' => $parent->id,
    ]);

    $replies = Message::factory()->count(3)->create([
        'channel_id' => $channel->id,
        'thread_id' => $thread->id,
        'user_id' => $user->id,
    ]);

    Passport::actingAs($user);

    $this->deleteJson("/api/messages/{$parent->id}")->assertNoContent();

    expect(Thread::find($thread->id))->toBeNull()
        ->and(Message::find($parent->id))->toBeNull();

    foreach ($replies as $reply) {
        expect(Message::find($reply->id))->toBeNull();
    }
});
