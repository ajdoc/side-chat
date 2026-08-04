<?php

use App\Events\FriendshipRemoved;
use App\Events\FriendshipUpdated;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;

/** Two strangers, no server between them. */
function twoStrangers(): array
{
    return [User::factory()->create(), User::factory()->create()];
}

function befriend(User $a, User $b): Friendship
{
    return Friendship::create([
        'user_id' => $a->id,
        'friend_id' => $b->id,
        'status' => Friendship::ACCEPTED,
        'pair_key' => Friendship::pairKey($a->id, $b->id),
    ]);
}

it('sends a friend request and tells both people', function () {
    Event::fake([FriendshipUpdated::class]);
    [$me, $them] = twoStrangers();

    Passport::actingAs($me);
    $response = $this->postJson('/api/friends', ['user_id' => $them->id])->assertOk();

    expect($response->json('data.status'))->toBe('pending')
        ->and($response->json('data.direction'))->toBe('outgoing')
        ->and($response->json('data.user.id'))->toBe($them->id);

    Event::assertDispatched(FriendshipUpdated::class);
});

it('finds someone by their exact name', function () {
    $me = User::factory()->create();
    $them = User::factory()->create(['name' => 'Ana Requena']);

    Passport::actingAs($me);
    $this->postJson('/api/friends', ['name' => 'Ana Requena'])
        ->assertOk()
        ->assertJsonPath('data.user.id', $them->id);

    // Partial matches are not a directory you can page through.
    $this->postJson('/api/friends', ['name' => 'Ana'])->assertStatus(422);
});

it('reads a request as incoming to the person who was asked', function () {
    [$me, $them] = twoStrangers();

    Passport::actingAs($me);
    $this->postJson('/api/friends', ['user_id' => $them->id])->assertOk();

    Passport::actingAs($them);
    $this->getJson('/api/friends/requests')
        ->assertOk()
        ->assertJsonPath('data.0.direction', 'incoming')
        ->assertJsonPath('data.0.user.id', $me->id);
});

it('turns two crossing requests into one friendship', function () {
    [$me, $them] = twoStrangers();

    Passport::actingAs($me);
    $this->postJson('/api/friends', ['user_id' => $them->id])->assertOk();

    // They pressed Add before seeing ours. That's a yes, not a second row.
    Passport::actingAs($them);
    $this->postJson('/api/friends', ['user_id' => $me->id])
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted');

    expect(Friendship::count())->toBe(1);
});

it('accepts a request only from the person who was asked', function () {
    Event::fake([FriendshipUpdated::class]);
    [$me, $them] = twoStrangers();

    Passport::actingAs($me);
    $friendship = Friendship::findOrFail(
        $this->postJson('/api/friends', ['user_id' => $them->id])->json('data.id'),
    );

    // The requester accepting their own request would make friendship unilateral.
    $this->postJson("/api/friends/{$friendship->id}/accept")->assertForbidden();

    Passport::actingAs($them);
    $this->postJson("/api/friends/{$friendship->id}/accept")
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted');

    Passport::actingAs($me);
    $this->getJson('/api/friends')->assertOk()->assertJsonPath('data.0.id', $them->id);
});

it('shows a friend to both sides of the row', function () {
    [$a, $b] = twoStrangers();
    befriend($a, $b);

    Passport::actingAs($b);
    $this->getJson('/api/friends')->assertOk()->assertJsonPath('data.0.id', $a->id);
});

it('forgets a declined request rather than recording the refusal', function () {
    Event::fake([FriendshipRemoved::class]);
    [$me, $them] = twoStrangers();

    Passport::actingAs($me);
    $id = $this->postJson('/api/friends', ['user_id' => $them->id])->json('data.id');

    Passport::actingAs($them);
    $this->postJson("/api/friends/{$id}/decline")->assertNoContent();

    expect(Friendship::count())->toBe(0);
    Event::assertDispatched(FriendshipRemoved::class);

    // And asking again afterwards is allowed — a decline is not a wall.
    Passport::actingAs($me);
    $this->postJson('/api/friends', ['user_id' => $them->id])->assertOk();
});

it('lets either party unfriend, and lets a requester take it back', function () {
    [$a, $b] = twoStrangers();
    $friendship = befriend($a, $b);

    Passport::actingAs($b);
    $this->deleteJson("/api/friends/{$friendship->id}")->assertNoContent();

    expect(Friendship::count())->toBe(0);
});

it('keeps strangers out of the friendship', function () {
    [$a, $b] = twoStrangers();
    $friendship = befriend($a, $b);

    Passport::actingAs(User::factory()->create());
    $this->deleteJson("/api/friends/{$friendship->id}")->assertForbidden();
});

it('lets friends dm each other without sharing a server', function () {
    [$a, $b] = twoStrangers();

    // Strangers can't — this is the rule the friendship is buying its way past.
    Passport::actingAs($a);
    $this->postJson('/api/conversations/dm', ['user_id' => $b->id])->assertStatus(422);

    befriend($a, $b);

    $this->postJson('/api/conversations/dm', ['user_id' => $b->id])->assertOk();
});

it('blocks someone, ending the friendship and closing the dm door both ways', function () {
    [$a, $b] = twoStrangers();
    befriend($a, $b);

    Passport::actingAs($a);
    $this->postJson('/api/friends/block', ['user_id' => $b->id])
        ->assertOk()
        ->assertJsonPath('data.status', 'blocked');

    // Not friends any more — one row, one state.
    $this->getJson('/api/friends')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/conversations/dm', ['user_id' => $b->id])->assertStatus(422);

    // And the wall works from the other side too, which is the point of a wall.
    Passport::actingAs($b);
    $this->postJson('/api/conversations/dm', ['user_id' => $a->id])->assertStatus(422);
    $this->postJson('/api/friends', ['user_id' => $a->id])->assertStatus(422);
});

it('blocks people you share a server with too', function () {
    [$me, $them] = twoMembers();

    Passport::actingAs($me);
    $this->postJson('/api/conversations/dm', ['user_id' => $them->id])->assertOk();

    $this->postJson('/api/friends/block', ['user_id' => $them->id])->assertOk();

    Passport::actingAs($them);
    $this->postJson('/api/conversations/dm', ['user_id' => $me->id])->assertStatus(422);
});

it('lets only the blocker unblock', function () {
    [$a, $b] = twoStrangers();

    Passport::actingAs($a);
    $this->postJson('/api/friends/block', ['user_id' => $b->id])->assertOk();

    // The blocked person can neither take the wall down nor overwrite it with their own.
    Passport::actingAs($b);
    $this->deleteJson("/api/friends/block/{$a->id}")->assertForbidden();
    $this->postJson('/api/friends/block', ['user_id' => $a->id])->assertForbidden();

    Passport::actingAs($a);
    $this->getJson('/api/friends/blocked')->assertOk()->assertJsonPath('data.0.user.id', $b->id);
    $this->deleteJson("/api/friends/block/{$b->id}")->assertNoContent();

    expect(Friendship::count())->toBe(0);
});

it('leaves a blocked person out of your contacts', function () {
    [$me, $them] = twoMembers();

    Passport::actingAs($me);
    $this->getJson('/api/conversations/contacts')->assertOk()->assertJsonCount(1, 'data');

    $this->postJson('/api/friends/block', ['user_id' => $them->id])->assertOk();
    $this->getJson('/api/conversations/contacts')->assertOk()->assertJsonCount(0, 'data');
});

it('offers friends as contacts even with no server in common', function () {
    [$a, $b] = twoStrangers();
    befriend($a, $b);

    Passport::actingAs($a);
    $this->getJson('/api/conversations/contacts')->assertOk()->assertJsonPath('data.0.id', $b->id);
});

it('refuses to friend yourself', function () {
    $me = User::factory()->create();

    Passport::actingAs($me);
    $this->postJson('/api/friends', ['user_id' => $me->id])->assertStatus(422);
});
