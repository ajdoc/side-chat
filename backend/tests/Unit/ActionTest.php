<?php

use App\Actions\Channel\CreateChannelAction;
use App\Actions\Message\DeleteMessageAction;
use App\Actions\Message\UpdateMessageAction;
use App\Actions\Server\CreateServerAction;
use App\DTOs\Channel\CreateChannelData;
use App\DTOs\Message\UpdateMessageData;
use App\DTOs\Server\CreateServerData;
use App\Models\Message;
use App\Models\Thread;
use App\Models\User;

it('creates a server with the creator as owner and member', function () {
    $user = User::factory()->create();

    $server = app(CreateServerAction::class)
        ->handle($user, CreateServerData::fromArray(['name' => 'HQ']));

    expect($server->name)->toBe('HQ')
        ->and($server->owner_id)->toBe($user->id)
        ->and($server->hasMember($user))->toBeTrue();
});

it('assigns an incrementing position to new channels', function () {
    [, $server] = ownerWithServer();
    $action = app(CreateChannelAction::class);

    $first = $action->handle($server, CreateChannelData::fromArray(['name' => 'a', 'type' => 'text']));
    $second = $action->handle($server, CreateChannelData::fromArray(['name' => 'b', 'type' => 'text']));

    expect($second->position)->toBeGreaterThan($first->position);
});

it('syncs the thread title when the parent message is edited (rule 2)', function () {
    [$user, , $channel] = ownerWithChannel();
    $parent = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);
    $thread = Thread::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'message_id' => $parent->id,
        'name' => 'Old',
    ]);

    app(UpdateMessageAction::class)
        ->handle($parent, UpdateMessageData::fromArray(['body' => 'New title']));

    expect($thread->fresh()->name)->toBe('New title')
        ->and($parent->fresh()->edited_at)->not->toBeNull();
});

it('cascades thread deletion when the parent message is deleted (rule 3)', function () {
    [$user, , $channel] = ownerWithChannel();
    $parent = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);
    $thread = Thread::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'message_id' => $parent->id,
    ]);
    $reply = Message::factory()->create([
        'channel_id' => $channel->id,
        'thread_id' => $thread->id,
        'user_id' => $user->id,
    ]);

    app(DeleteMessageAction::class)->handle($parent);

    expect(Thread::find($thread->id))->toBeNull()
        ->and(Message::find($reply->id))->toBeNull()
        ->and(Message::find($parent->id))->toBeNull();
});
