<?php

use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use Laravel\Passport\Passport;

/** A server with an owner and two extra members, plus a channel. */
function serverWithMembers(): array
{
    [$owner, $server, $channel] = ownerWithChannel();

    $bob = User::factory()->create(['name' => 'Bob']);
    $carol = User::factory()->create(['name' => 'Carol']);
    $server->members()->attach($bob->id, ['role' => 'member']);
    $server->members()->attach($carol->id, ['role' => 'member']);

    return [$owner, $server, $channel, $bob, $carol];
}

it('splits the members into who has seen the message and who has not', function () {
    [$owner, , $channel, $bob, $carol] = serverWithMembers();
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);

    Passport::actingAs($bob);
    $this->postJson("/api/channels/{$channel->id}/read", ['message_id' => $message->id])->assertOk();

    Passport::actingAs($owner);
    $response = $this->getJson("/api/messages/{$message->id}/info")->assertOk();

    expect($response->json('data.receipts_tracked'))->toBeTrue()
        ->and($response->json('data.seen_by'))->toHaveCount(1)
        ->and($response->json('data.seen_by.0.user.id'))->toBe($bob->id)
        ->and($response->json('data.seen_by.0.read_at'))->not->toBeNull()
        ->and($response->json('data.not_seen_by'))->toHaveCount(1)
        ->and($response->json('data.not_seen_by.0.id'))->toBe($carol->id);
});

it('counts a marker further down the channel as having seen an earlier message', function () {
    [$owner, , $channel, $bob] = serverWithMembers();
    $first = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);
    $later = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);

    // Bob read to the end — which means he saw everything before it too.
    Passport::actingAs($bob);
    $this->postJson("/api/channels/{$channel->id}/read", ['message_id' => $later->id])->assertOk();

    Passport::actingAs($owner);
    $response = $this->getJson("/api/messages/{$first->id}/info")->assertOk();

    expect(collect($response->json('data.seen_by'))->pluck('user.id'))->toContain($bob->id);
});

it('leaves the sender out of both lists', function () {
    [$owner, , $channel] = serverWithMembers();
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);

    Passport::actingAs($owner);
    $response = $this->getJson("/api/messages/{$message->id}/info")->assertOk();

    $everyone = collect($response->json('data.seen_by'))->pluck('user.id')
        ->merge(collect($response->json('data.not_seen_by'))->pluck('id'));

    expect($everyone)->not->toContain($owner->id);
});

it('lists who reacted with what', function () {
    [$owner, , $channel, $bob, $carol] = serverWithMembers();
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);

    Passport::actingAs($bob);
    $this->postJson("/api/messages/{$message->id}/reactions", ['emoji' => '👍'])->assertOk();
    Passport::actingAs($carol);
    $this->postJson("/api/messages/{$message->id}/reactions", ['emoji' => '👍'])->assertOk();
    Passport::actingAs($owner);
    $this->postJson("/api/messages/{$message->id}/reactions", ['emoji' => '🎉'])->assertOk();

    $response = $this->getJson("/api/messages/{$message->id}/info")->assertOk();

    $reactions = collect($response->json('data.reactions'));

    expect($reactions)->toHaveCount(2)
        ->and($reactions->firstWhere('emoji', '👍')['count'])->toBe(2)
        ->and(collect($reactions->firstWhere('emoji', '👍')['users'])->pluck('name')->all())
        ->toEqualCanonicalizing(['Bob', 'Carol'])
        ->and($reactions->firstWhere('emoji', '🎉')['count'])->toBe(1);
});

it('reports that receipts do not apply to a thread reply', function () {
    [$owner, , $channel] = serverWithMembers();
    $thread = Thread::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);
    $reply = Message::factory()->create([
        'channel_id' => $channel->id,
        'thread_id' => $thread->id,
        'user_id' => $owner->id,
    ]);

    Passport::actingAs($owner);
    $response = $this->getJson("/api/messages/{$reply->id}/info")->assertOk();

    // Read markers only ever point at main-timeline messages, so a "Seen by" here would
    // be a guess dressed up as a fact.
    expect($response->json('data.receipts_tracked'))->toBeFalse();
});

it('forbids non-members from opening message info', function () {
    [$owner, , $channel] = serverWithMembers();
    $message = Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $owner->id]);

    Passport::actingAs(User::factory()->create());

    $this->getJson("/api/messages/{$message->id}/info")->assertForbidden();
});
