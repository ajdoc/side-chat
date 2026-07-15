<?php

use App\Events\ChannelReadUpdated;
use App\Models\Channel;
use App\Models\ChannelRead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;

it('marks a channel read up to a message', function () {
    [$user, , $channel] = ownerWithChannel();
    $messages = Message::factory()->count(3)->create(['channel_id' => $channel->id, 'user_id' => $user->id]);
    $second = $messages[1];

    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/read", ['message_id' => $second->id])
        ->assertOk()
        ->assertJson(['last_read_message_id' => $second->id]);

    expect(ChannelRead::where('user_id', $user->id)->value('last_read_message_id'))->toBe($second->id);
});

it('marks everything read when no message is given', function () {
    [$user, , $channel] = ownerWithChannel();
    $messages = Message::factory()->count(3)->create(['channel_id' => $channel->id, 'user_id' => $user->id]);

    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/read")
        ->assertOk()
        ->assertJson(['last_read_message_id' => $messages->last()->id]);
});

it('never moves the read marker backwards', function () {
    [$user, , $channel] = ownerWithChannel();
    $messages = Message::factory()->count(3)->create(['channel_id' => $channel->id, 'user_id' => $user->id]);

    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/read", ['message_id' => $messages->last()->id])->assertOk();

    // Out-of-order "I read up to message 1" must not undo it.
    $this->postJson("/api/channels/{$channel->id}/read", ['message_id' => $messages->first()->id])
        ->assertOk()
        ->assertJson(['last_read_message_id' => $messages->last()->id]);
});

it('refuses a message that belongs to another channel', function () {
    [$user, $server, $channel] = ownerWithChannel();
    $other = Channel::factory()->create(['server_id' => $server->id]);
    $foreign = Message::factory()->create(['channel_id' => $other->id, 'user_id' => $user->id]);

    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/read", ['message_id' => $foreign->id])
        ->assertJsonValidationErrors('message_id');

    expect(ChannelRead::count())->toBe(0);
});

it('broadcasts only when the marker actually moves', function () {
    [$user, , $channel] = ownerWithChannel();
    $messages = Message::factory()->count(2)->create(['channel_id' => $channel->id, 'user_id' => $user->id]);

    Event::fake([ChannelReadUpdated::class]);
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/read", ['message_id' => $messages->last()->id])->assertOk();
    Event::assertDispatchedTimes(ChannelReadUpdated::class, 1);

    // Same id again — nothing changed, so nobody needs telling.
    $this->postJson("/api/channels/{$channel->id}/read", ['message_id' => $messages->last()->id])->assertOk();
    Event::assertDispatchedTimes(ChannelReadUpdated::class, 1);
});

it('lists where each member has read up to', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    $messages = Message::factory()->count(2)->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);

    Passport::actingAs($member);
    $this->postJson("/api/channels/{$channel->id}/read", ['message_id' => $messages->first()->id])->assertOk();

    Passport::actingAs($owner);
    $response = $this->getJson("/api/channels/{$channel->id}/reads")->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.user.id'))->toBe($member->id)
        ->and($response->json('data.0.last_read_message_id'))->toBe($messages->first()->id);
});

it('counts unread messages per channel, ignoring your own and thread replies', function () {
    [$owner, $server, $channel] = ownerWithChannel();
    $member = User::factory()->create();
    $server->members()->attach($member->id, ['role' => 'member']);

    $first = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);
    Message::factory()->count(2)->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);
    // The member's own message doesn't count against them...
    Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $member->id]);

    Passport::actingAs($member);

    // Never read → everything from other people is unread (3 of the 4).
    $response = $this->getJson("/api/servers/{$server->id}/channels")->assertOk();
    expect($response->json('data.0.unread_count'))->toBe(3);

    $this->postJson("/api/channels/{$channel->id}/read", ['message_id' => $first->id])->assertOk();

    $response = $this->getJson("/api/servers/{$server->id}/channels")->assertOk();
    expect($response->json('data.0.unread_count'))->toBe(2);

    $this->postJson("/api/channels/{$channel->id}/read")->assertOk();

    $response = $this->getJson("/api/servers/{$server->id}/channels")->assertOk();
    expect($response->json('data.0.unread_count'))->toBe(0);
});

it('forbids non-members from reading or writing read state', function () {
    [, , $channel] = ownerWithChannel();
    $stranger = User::factory()->create();

    Passport::actingAs($stranger);

    $this->getJson("/api/channels/{$channel->id}/reads")->assertForbidden();
    $this->postJson("/api/channels/{$channel->id}/read")->assertForbidden();
});
