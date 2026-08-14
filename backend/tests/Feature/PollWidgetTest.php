<?php

use App\Models\AppPoll;
use App\Models\Message;
use App\Models\User;
use App\Models\Widget;
use Laravel\Passport\Passport;

/**
 * The `p!` poll card.
 *
 * These used to assert against the widget's own JSON state — a question, options and a list of
 * voters, all in one blob. That blob is gone: the widget is a pointer at an {@see AppPoll}, the
 * same row the Polls app draws. So the assertions moved to the poll, and the one worth making
 * loudest is that a `p!new` in the timeline lands on the channel's wall.
 */

/**
 * Note on status codes: only a command that *drops a card* answers 201. One that merely
 * updates state (`p!add`, `p!vote`, `p!close`) posts no message and answers 200, and an
 * ephemeral reply like `p!help` answers 200 with a negative-id system message. These
 * assertions say `assertSuccessful()` where that distinction isn't what's under test — the
 * 200/201 split predates this file's rewrite and is unrelated to polls.
 */

/** The channel's poll widget, or null before one's been started. */
function pollWidget(int $channelId): ?Widget
{
    return Widget::where('channel_id', $channelId)->where('type', 'poll')->first();
}

/** The poll that widget points at. */
function widgetPoll(int $channelId): ?AppPoll
{
    $id = pollWidget($channelId)?->state['poll_id'] ?? null;

    return $id ? AppPoll::with('options')->find($id) : null;
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

    $poll = widgetPoll($channel->id);
    expect($poll)->not->toBeNull()
        ->and($poll->question)->toBe('Lunch?')
        ->and($poll->options)->toHaveCount(3)
        ->and($poll->options[0]->label)->toBe('Pizza')
        ->and($poll->isOpen())->toBeTrue()
        ->and($poll->allowsMultiple())->toBeFalse();
});

it('puts a `p!` poll on the channel’s Polls wall', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!new Lunch? | Pizza | Sushi'])
        ->assertCreated();

    // The whole point of the unification: one poll, reachable from the timeline and from the
    // app. Before this it was two objects that couldn't see each other.
    $wall = $this->getJson("/api/channels/{$channel->id}/polls")->assertOk()->json('data');

    expect($wall)->toHaveCount(1)
        ->and($wall[0]['question'])->toBe('Lunch?')
        ->and($wall[0]['options'])->toHaveCount(2);
});

it('adds options in place without spawning a second card', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!new Best pet?'])->assertCreated();
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!add Cat'])->assertSuccessful();
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!add Dog'])->assertSuccessful();

    expect(Message::where('type', 'widget')->count())->toBe(1)
        ->and(widgetPoll($channel->id)->options)->toHaveCount(2);
});

it('toggles a vote via the card action and counts the voter', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!new Tabs or spaces? | Tabs | Spaces'])->assertCreated();
    $widget = pollWidget($channel->id);
    $tabs = widgetPoll($channel->id)->options[0]->id;

    $this->postJson("/api/widgets/{$widget->id}/action", ['action' => 'vote', 'payload' => ['id' => $tabs]])->assertNoContent();
    expect(widgetPoll($channel->id)->votes()->count())->toBe(1);

    // Voting the same option again toggles it back off.
    $this->postJson("/api/widgets/{$widget->id}/action", ['action' => 'vote', 'payload' => ['id' => $tabs]])->assertNoContent();
    expect(widgetPoll($channel->id)->votes()->count())->toBe(0);
});

it('replaces a prior pick in single-choice mode but keeps both when multi', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!new Pick | A | B'])->assertCreated();
    $poll = widgetPoll($channel->id);
    [$a, $b] = [$poll->options[0]->id, $poll->options[1]->id];

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => "p!vote {$a}"])->assertSuccessful();
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => "p!vote {$b}"])->assertSuccessful();

    // Single-choice: the second pick replaces the first.
    expect(widgetPoll($channel->id)->votes()->pluck('option_id')->all())->toBe([$b]);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!multi'])->assertSuccessful();
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => "p!vote {$a}"])->assertSuccessful();

    expect(widgetPoll($channel->id)->votes()->count())->toBe(2);
});

it('rejects a vote once the poll is closed', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!new Ship it? | Yes | No'])->assertCreated();
    $option = widgetPoll($channel->id)->options[0]->id;

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!close'])->assertSuccessful();
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => "p!vote {$option}"])->assertSuccessful();

    // Closing refuses new votes rather than forgetting the old ones.
    expect(widgetPoll($channel->id)->votes()->count())->toBe(0)
        ->and(widgetPoll($channel->id)->isOpen())->toBeFalse();
});

it('clears every vote but keeps the question and options', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!new Best pet? | Cat | Dog'])->assertCreated();
    $option = widgetPoll($channel->id)->options[0]->id;
    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => "p!vote {$option}"])->assertSuccessful();

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!clear'])->assertSuccessful();

    $poll = widgetPoll($channel->id);
    expect($poll->votes()->count())->toBe(0)
        ->and($poll->question)->toBe('Best pet?')
        ->and($poll->options)->toHaveCount(2);
});

it('answers `p!help` with an ephemeral note that is never persisted', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $res = $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!help'])->assertSuccessful();

    expect($res->json('data.type'))->toBe('system')
        ->and($res->json('data.id'))->toBeLessThan(0)
        ->and(Message::count())->toBe(0);
});

it('forbids a non-member from voting in a channel’s poll', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $this->postJson("/api/channels/{$channel->id}/messages", ['body' => 'p!new Pick | A | B'])->assertCreated();
    $widget = pollWidget($channel->id);
    $option = widgetPoll($channel->id)->options[0]->id;

    Passport::actingAs(User::factory()->create());
    $this->postJson("/api/widgets/{$widget->id}/action", ['action' => 'vote', 'payload' => ['id' => $option]])
        ->assertForbidden();
});
