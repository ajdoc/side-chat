<?php

use App\Models\AppPoll;
use App\Models\User;
use Laravel\Passport\Passport;

/**
 * The Polls app.
 *
 * The cases worth pinning down are about *counting*: a vote that replaces rather than
 * accumulates, a multiple-choice poll where people and rows differ, and the fact that raw votes
 * never cross the wire.
 */
it('creates a yes/no poll with its options written for it', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $res = $this->postJson("/api/channels/{$channel->id}/polls", [
        'type' => 'yes_no', 'question' => 'Does your app support themes?',
    ])->assertCreated();

    expect(collect($res->json('data.options'))->pluck('label')->all())->toBe(['Yes', 'No']);
});

it('refuses a poll with fewer than two options', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $this->postJson("/api/channels/{$channel->id}/polls", [
        'type' => 'single', 'question' => 'One?', 'options' => ['Only'],
    ])->assertStatus(422);
});

it('replaces a single-choice vote rather than accumulating it', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $poll = $this->postJson("/api/channels/{$channel->id}/polls", [
        'type' => 'single', 'question' => 'Where?', 'options' => ['Desktop', 'Mobile', 'Both'],
    ])->json('data');

    $first = $poll['options'][0]['id'];
    $second = $poll['options'][1]['id'];

    $this->putJson("/api/channels/{$channel->id}/polls/{$poll['id']}/vote", ['option_ids' => [$first]])
        ->assertOk();

    // Changing your mind is one call that replaces — see the controller on why it isn't a delta.
    $res = $this->putJson("/api/channels/{$channel->id}/polls/{$poll['id']}/vote", ['option_ids' => [$second]])
        ->assertOk();

    expect($res->json('data.options.0.votes'))->toBe(0)
        ->and($res->json('data.options.1.votes'))->toBe(1)
        ->and($res->json('data.vote_count'))->toBe(1)
        ->and($res->json('data.my_option_ids'))->toBe([$second]);

    // An empty set is how you withdraw.
    $res = $this->putJson("/api/channels/{$channel->id}/polls/{$poll['id']}/vote", ['option_ids' => []])
        ->assertOk();
    expect($res->json('data.vote_count'))->toBe(0);
});

it('refuses several answers on a single-choice poll but allows them on a multiple', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $single = $this->postJson("/api/channels/{$channel->id}/polls", [
        'type' => 'single', 'question' => 'One?', 'options' => ['A', 'B'],
    ])->json('data');

    $this->putJson("/api/channels/{$channel->id}/polls/{$single['id']}/vote", [
        'option_ids' => collect($single['options'])->pluck('id')->all(),
    ])->assertStatus(422);

    $multi = $this->postJson("/api/channels/{$channel->id}/polls", [
        'type' => 'multiple', 'question' => 'Which OS?', 'options' => ['Windows', 'macOS', 'Linux'],
    ])->json('data');

    $res = $this->putJson("/api/channels/{$channel->id}/polls/{$multi['id']}/vote", [
        'option_ids' => collect($multi['options'])->take(2)->pluck('id')->all(),
    ])->assertOk();

    // Two rows from one person: the counts differ, which is why both are reported.
    expect($res->json('data.vote_count'))->toBe(2)
        ->and($res->json('data.voter_count'))->toBe(1);
});

it('never puts raw votes on the wire', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $poll = $this->postJson("/api/channels/{$channel->id}/polls", [
        'type' => 'single', 'question' => 'Secret?', 'options' => ['A', 'B'], 'anonymous' => true,
    ])->json('data');

    $this->putJson("/api/channels/{$channel->id}/polls/{$poll['id']}/vote", [
        'option_ids' => [$poll['options'][0]['id']],
    ])->assertOk();

    // On an anonymous poll a list of votes would be the answer to the question the anonymity
    // was for. Counts and your own picks are all that's ever sent.
    $res = $this->getJson("/api/channels/{$channel->id}/polls/{$poll['id']}")->assertOk();
    expect($res->json('data'))->not->toHaveKey('votes');
});

it('refuses a vote once the poll is closed, and keeps the votes it had', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $poll = $this->postJson("/api/channels/{$channel->id}/polls", [
        'type' => 'yes_no', 'question' => 'Ship it?',
    ])->json('data');

    $this->putJson("/api/channels/{$channel->id}/polls/{$poll['id']}/vote", [
        'option_ids' => [$poll['options'][0]['id']],
    ])->assertOk();

    $this->patchJson("/api/channels/{$channel->id}/polls/{$poll['id']}", ['closed' => true])->assertOk();

    $this->putJson("/api/channels/{$channel->id}/polls/{$poll['id']}/vote", ['option_ids' => []])
        ->assertStatus(422);

    // Closing refuses new votes; it doesn't forget the old ones.
    expect($this->getJson("/api/channels/{$channel->id}/polls/{$poll['id']}")->json('data.vote_count'))->toBe(1);
});

it('toggles a reaction on and off', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $poll = $this->postJson("/api/channels/{$channel->id}/polls", [
        'type' => 'yes_no', 'question' => 'Themes?',
    ])->json('data');

    $on = $this->postJson("/api/channels/{$channel->id}/apps/app_poll/{$poll['id']}/reactions", ['emoji' => '❤️'])
        ->assertOk();
    expect($on->json('reactions.0'))->toMatchArray(['emoji' => '❤️', 'count' => 1, 'reacted' => true]);

    // The same click again removes it — reacting and un-reacting are one gesture.
    $off = $this->postJson("/api/channels/{$channel->id}/apps/app_poll/{$poll['id']}/reactions", ['emoji' => '❤️'])
        ->assertOk();
    expect($off->json('reactions'))->toBe([]);
});

it('takes a poll’s votes, comments and reactions with it when deleted', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs($owner);

    $poll = $this->postJson("/api/channels/{$channel->id}/polls", [
        'type' => 'yes_no', 'question' => 'Themes?',
    ])->json('data');

    $this->putJson("/api/channels/{$channel->id}/polls/{$poll['id']}/vote", ['option_ids' => [$poll['options'][0]['id']]]);
    $this->postJson("/api/channels/{$channel->id}/apps/app_poll/{$poll['id']}/comments", ['body' => 'custom themes?']);
    $this->postJson("/api/channels/{$channel->id}/apps/app_poll/{$poll['id']}/reactions", ['emoji' => '👍']);

    $this->deleteJson("/api/channels/{$channel->id}/polls/{$poll['id']}")->assertNoContent();

    // The comments and reactions are polymorphic, so no foreign key cascades them — this is
    // the model event doing it. It silently didn't, once.
    expect(AppPoll::count())->toBe(0)
        ->and(DB::table('app_poll_votes')->count())->toBe(0)
        ->and(DB::table('app_comments')->count())->toBe(0)
        ->and(DB::table('app_reactions')->count())->toBe(0);
});

it('hides a channel’s polls from someone outside it', function () {
    [$owner, , $channel] = ownerWithChannel();
    Passport::actingAs(User::factory()->create());

    $this->getJson("/api/channels/{$channel->id}/polls")->assertForbidden();
});
