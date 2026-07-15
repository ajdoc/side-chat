<?php

use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use App\Services\MessageService;

it('wires up the server relationships', function () {
    [$user, $server] = ownerWithServer();

    expect($server->owner->is($user))->toBeTrue()
        ->and($server->members->pluck('id')->all())->toContain($user->id)
        ->and($user->servers->pluck('id')->all())->toContain($server->id)
        ->and($user->ownedServers->pluck('id')->all())->toContain($server->id);
});

it('wires up message, reply and thread relationships', function () {
    [$user, , $channel] = ownerWithChannel();

    $parent = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);
    $reply = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'reply_to_id' => $parent->id,
    ]);
    $thread = Thread::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'message_id' => $parent->id,
    ]);

    expect($reply->replyTo->is($parent))->toBeTrue()
        ->and($parent->startedThread->is($thread))->toBeTrue()
        ->and($thread->parentMessage->is($parent))->toBeTrue()
        ->and($thread->channel->is($channel))->toBeTrue();
});

/**
 * The app runs with Model::preventLazyLoading() outside production, so this would
 * throw a LazyLoadingViolationException if the service failed to eager-load
 * everything MessageResource touches (user, replyTo.user, startedThread).
 */
it('eager-loads everything the message resource needs (no N+1)', function () {
    [$user, , $channel] = ownerWithChannel();

    $parent = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);
    Thread::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'message_id' => $parent->id,
    ]);
    Message::factory()->count(3)->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'reply_to_id' => $parent->id,
    ]);

    $page = app(MessageService::class)->forChannel($channel);

    // Touch every relation the resource serialises — must not lazy-load.
    foreach ($page['messages'] as $message) {
        $message->user->name;
        $message->replyTo?->user?->name;
        $message->startedThread?->name;
    }

    expect($page['messages'])->toHaveCount(4)
        ->and($page['has_more'])->toBeFalse();
});
