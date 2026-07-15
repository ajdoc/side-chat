<?php

use App\Events\ReactionToggled;
use App\Models\Message;
use App\Models\Reaction;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;

it('adds a reaction and returns the grouped summary', function () {
    [$user, , $channel] = ownerWithChannel();
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);

    Passport::actingAs($user);

    $response = $this->postJson("/api/messages/{$message->id}/reactions", ['emoji' => '🎉'])->assertOk();

    expect($response->json('data.reactions'))->toHaveCount(1)
        ->and($response->json('data.reactions.0.emoji'))->toBe('🎉')
        ->and($response->json('data.reactions.0.count'))->toBe(1)
        ->and($response->json('data.reactions.0.users.0.id'))->toBe($user->id)
        ->and($response->json('data.reactions.0.users.0.name'))->toBe($user->name);
});

it('toggles the same emoji off when the user reacts with it twice', function () {
    [$user, , $channel] = ownerWithChannel();
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);

    Passport::actingAs($user);

    $this->postJson("/api/messages/{$message->id}/reactions", ['emoji' => '👍'])->assertOk();
    expect(Reaction::count())->toBe(1);

    $response = $this->postJson("/api/messages/{$message->id}/reactions", ['emoji' => '👍'])->assertOk();

    expect(Reaction::count())->toBe(0)
        ->and($response->json('data.reactions'))->toBe([]);
});

it('groups the same emoji from different users, and keeps different emoji apart', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $other = User::factory()->create();
    $server->members()->attach($other->id, ['role' => 'member']);

    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);

    Passport::actingAs($owner);
    $this->postJson("/api/messages/{$message->id}/reactions", ['emoji' => '👍'])->assertOk();
    $this->postJson("/api/messages/{$message->id}/reactions", ['emoji' => '🚀'])->assertOk();

    Passport::actingAs($other);
    $response = $this->postJson("/api/messages/{$message->id}/reactions", ['emoji' => '👍'])->assertOk();

    $reactions = collect($response->json('data.reactions'));

    // Most-reacted first.
    expect($reactions)->toHaveCount(2)
        ->and($reactions->first()['emoji'])->toBe('👍')
        ->and($reactions->first()['count'])->toBe(2)
        ->and(collect($reactions->first()['users'])->pluck('id')->all())
        ->toEqualCanonicalizing([$owner->id, $other->id])
        ->and($reactions->last()['emoji'])->toBe('🚀')
        ->and($reactions->last()['count'])->toBe(1);
});

it('lets a member react to someone else’s message, but forbids non-members', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);
    $stranger = User::factory()->create();

    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);

    // Unlike editing, reacting is not sender-only.
    Passport::actingAs($member);
    $this->postJson("/api/messages/{$message->id}/reactions", ['emoji' => '❤️'])->assertOk();

    Passport::actingAs($stranger);
    $this->postJson("/api/messages/{$message->id}/reactions", ['emoji' => '❤️'])->assertForbidden();

    expect(Reaction::count())->toBe(1);
});

it('rejects anything that is not an emoji', function (string $emoji) {
    [$user, , $channel] = ownerWithChannel();
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);

    Passport::actingAs($user);

    $this->postJson("/api/messages/{$message->id}/reactions", ['emoji' => $emoji])
        ->assertJsonValidationErrors('emoji');

    expect(Reaction::count())->toBe(0);
})->with([
    'plain text' => 'lgtm',
    'a single letter' => 'a',
    'html' => '<img src=x onerror=alert(1)>',
    'empty' => '',
]);

it('accepts a multi-codepoint emoji (zwj sequence, skin tone, keycap)', function (string $emoji) {
    [$user, , $channel] = ownerWithChannel();
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);

    Passport::actingAs($user);

    $this->postJson("/api/messages/{$message->id}/reactions", ['emoji' => $emoji])->assertOk();

    expect(Reaction::first()->emoji)->toBe($emoji);
})->with([
    'zwj sequence' => '👩‍💻',
    'skin tone' => '👍🏽',
    'keycap' => '1️⃣',
]);

it('broadcasts on the thread stream for a thread message, and the channel otherwise', function () {
    [$user, , $channel] = ownerWithChannel();
    $thread = Thread::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);

    $channelMessage = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);
    $threadMessage = Message::factory()->create([
        'channel_id' => $channel->id,
        'thread_id' => $thread->id,
        'user_id' => $user->id,
    ]);

    Event::fake([ReactionToggled::class]);
    Passport::actingAs($user);

    $this->postJson("/api/messages/{$channelMessage->id}/reactions", ['emoji' => '👍'])->assertOk();
    $this->postJson("/api/messages/{$threadMessage->id}/reactions", ['emoji' => '👍'])->assertOk();

    Event::assertDispatched(ReactionToggled::class, function (ReactionToggled $event) use ($channelMessage, $channel) {
        return $event->message->id === $channelMessage->id
            && $event->broadcastOn()[0]->name === 'private-channel.'.$channel->id;
    });

    Event::assertDispatched(ReactionToggled::class, function (ReactionToggled $event) use ($threadMessage, $thread) {
        return $event->message->id === $threadMessage->id
            && $event->broadcastOn()[0]->name === 'private-thread.'.$thread->id;
    });
});

it('drops a message’s reactions when the message is deleted', function () {
    [$user, , $channel] = ownerWithChannel();
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);
    Reaction::factory()->count(3)->create(['message_id' => $message->id]);

    Passport::actingAs($user);

    $this->deleteJson("/api/messages/{$message->id}")->assertNoContent();

    expect(Reaction::count())->toBe(0);
});
