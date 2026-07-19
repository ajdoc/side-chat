<?php

use App\Models\Message;
use App\Models\User;
use App\Models\Widget;
use Laravel\Passport\Passport;

/** The channel's poll widget, or null before one's been started. */
function pollWidget(int $channelId): ?Widget
{
    return Widget::where('channel_id', $channelId)->where('type', 'poll')->first();
}

it('starts a poll with `p!new`, carrying pipe-separated options, and drops a card', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $res = $this->postJson("/api/channels/{$channel->id}/messages", [
        'body' => 'p!new Lunch? | Pizza | Sushi | Tacos',
    ])->assertCreated();

    expect($res->json('data.type'))->toBe('widget')
        ->and($res->json('data.widget.type'))->toBe('poll')
        ->and(Message::where('body', 'like', 'p!new%')->exists())->toBeFalse();

    $state = pollWidget($channel->id)->state;
    expect($state['question'])->toBe('Lunch?')
        ->and($state['options'])->toHaveCount(3)
        ->and($state['options'][0]['text'])->toBe('Pizza')
        ->and($state['options'][0]['voters'])->toBeEmpty()
        ->and($state['closed'])->toBeFalse()
        ->and($state['multi'])->toBeFalse();
});

it('adds options in place without spawning a second card', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!new Best pet?'])->assertCreated();
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!add Cat'])->assertCreated();
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!add Dog'])->assertCreated();

    expect(Message::where('type', 'widget')->count())->toBe(1)
        ->and(pollWidget($channel->id)->state['options'])->toHaveCount(2);
});

it('toggles a vote via the card action and counts the voter', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!new Tabs or spaces? | Tabs | Spaces'])->assertCreated();
    $widget = pollWidget($channel->id);
    $tabs = $widget->state['options'][0]['id'];

    $this->postJson("/api/widgets/{$widget->id}/action", ['action' => 'vote', 'payload' => ['id' => $tabs]])->assertNoContent();
    expect($widget->fresh()->state['options'][0]['voters'])->toHaveCount(1)
        ->and($widget->fresh()->state['options'][0]['voters'][0]['id'])->toBe($user->id);

    // Voting the same option again toggles it back off.
    $this->postJson("/api/widgets/{$widget->id}/action", ['action' => 'vote', 'payload' => ['id' => $tabs]])->assertNoContent();
    expect($widget->fresh()->state['options'][0]['voters'])->toBeEmpty();
});

it('replaces a prior pick in single-choice mode but keeps both when multi', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!new Pick | A | B'])->assertCreated();
    $widget = pollWidget($channel->id);
    [$a, $b] = [$widget->state['options'][0]['id'], $widget->state['options'][1]['id']];

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => "p!vote {$a}"])->assertCreated();
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => "p!vote {$b}"])->assertCreated();

    // Single-choice: the second vote moved the voter off A onto B.
    $state = $widget->fresh()->state;
    expect($state['options'][0]['voters'])->toBeEmpty()
        ->and($state['options'][1]['voters'])->toHaveCount(1);

    // Flip to multi and vote A again — now the voter holds both.
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!multi'])->assertCreated();
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => "p!vote {$a}"])->assertCreated();
    $state = $widget->fresh()->state;
    expect($state['options'][0]['voters'])->toHaveCount(1)
        ->and($state['options'][1]['voters'])->toHaveCount(1);
});

it('rejects a vote once the poll is closed', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!new Q | Yes | No'])->assertCreated();
    $widget = pollWidget($channel->id);
    $yes = $widget->state['options'][0]['id'];

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!close'])->assertCreated();
    $this->postJson("/api/widgets/{$widget->id}/action", ['action' => 'vote', 'payload' => ['id' => $yes]])->assertNoContent();

    expect($widget->fresh()->state['options'][0]['voters'])->toBeEmpty()
        ->and($widget->fresh()->state['closed'])->toBeTrue();
});

it('clears every vote but keeps the question and options', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!new Q | One | Two'])->assertCreated();
    $widget = pollWidget($channel->id);
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => "p!vote {$widget->state['options'][0]['id']}"])->assertCreated();

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!clear'])->assertCreated();

    $state = $widget->fresh()->state;
    expect($state['options'])->toHaveCount(2)
        ->and($state['options'][0]['voters'])->toBeEmpty()
        ->and($state['question'])->toBe('Q');
});

it('answers `p!help` with an ephemeral note that is never persisted', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $res = $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!help'])->assertCreated();

    expect($res->json('data.type'))->toBe('system')
        ->and($res->json('data.id'))->toBeLessThan(0)
        ->and(Message::count())->toBe(0);
});

it('forbids a non-member from voting in a channel\'s poll', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!new Q | A | B'])->assertCreated();
    $widget = pollWidget($channel->id);

    Passport::actingAs(User::factory()->create());
    $this->postJson("/api/widgets/{$widget->id}/action", [
        'action' => 'vote',
        'payload' => ['id' => $widget->state['options'][0]['id']],
    ])->assertForbidden();
});
