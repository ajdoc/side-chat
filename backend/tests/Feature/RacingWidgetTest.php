<?php

use App\Models\Message;
use App\Models\User;
use App\Models\Widget;
use App\Services\Widgets\WidgetService;
use Laravel\Passport\Passport;

it('turns `r!race` into a racing widget and a card, not a chat message', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $res = $this->postJson("/api/channels/{$channel->id}/messages", [
        'body' => 'r!race',
    ])->assertCreated();

    expect($res->json('data.type'))->toBe('widget')
        ->and($res->json('data.widget.type'))->toBe('racing')
        ->and(Message::where('body', 'like', 'r!race%')->exists())->toBeFalse();

    $widget = Widget::where('channel_id', $channel->id)->where('type', 'racing')->sole();
    expect($widget->state['status'])->toBe('racing')
        ->and($widget->state['seed'])->toBeGreaterThan(0)
        ->and($widget->state['laps'])->toBeGreaterThan(0)
        ->and($widget->state['finishers'])->toBe(0)
        ->and($widget->state['players'])->toBeEmpty();
});

it('enrols a driver on join', function () {
    [$user, , $channel] = ownerWithChannel();
    $widget = raceWidget($channel->id, $user->id);

    app(WidgetService::class)->handleAction($widget, $user, 'join', []);

    $widget->refresh();
    expect($widget->state['players'][(string) $user->id]['name'])->toBe($user->name)
        ->and($widget->state['players'][(string) $user->id]['bestLap'])->toBeNull()
        ->and($widget->state['players'][(string) $user->id]['finished'])->toBeFalse();
});

it('keeps only the best lap and ignores impossibly fast ones', function () {
    [$user, , $channel] = ownerWithChannel();
    $widget = raceWidget($channel->id, $user->id);
    $svc = app(WidgetService::class);

    $svc->handleAction($widget, $user, 'lap', ['ms' => 42_000]);
    $svc->handleAction($widget, $user, 'lap', ['ms' => 38_500]); // a better lap — kept
    $svc->handleAction($widget, $user, 'lap', ['ms' => 50_000]); // slower — ignored for best
    $svc->handleAction($widget, $user, 'lap', ['ms' => 5]); // impossible — not counted at all

    $widget->refresh();
    expect($widget->state['players'][(string) $user->id]['bestLap'])->toBe(38_500)
        ->and($widget->state['players'][(string) $user->id]['lapsDone'])->toBe(3);
});

it('assigns finishing places in the order drivers take the flag', function () {
    [$owner, , $channel] = ownerWithChannel();
    $mate = User::factory()->create();
    $widget = raceWidget($channel->id, $owner->id);
    $svc = app(WidgetService::class);

    // Both take the wheel first (as the card does), so "everyone home" means both of them.
    $svc->handleAction($widget, $owner, 'join', []);
    $svc->handleAction($widget, $mate, 'join', []);

    $svc->handleAction($widget, $mate, 'finish', ['ms' => 120_000]);
    // The race isn't over yet — the owner is still out on track.
    $widget->refresh();
    expect($widget->state['status'])->toBe('racing');

    $svc->handleAction($widget, $owner, 'finish', ['ms' => 118_000]);
    $svc->handleAction($widget, $owner, 'finish', ['ms' => 1_000]); // already home — ignored

    $widget->refresh();
    expect($widget->state['players'][(string) $mate->id]['place'])->toBe(1)
        ->and($widget->state['players'][(string) $owner->id]['place'])->toBe(2)
        ->and($widget->state['finishers'])->toBe(2)
        ->and($widget->state['status'])->toBe('finished'); // both home → race over
});

/** A fresh, active race to act on. */
function raceWidget(int $channelId, int $userId): Widget
{
    return Widget::create([
        'channel_id' => $channelId,
        'type' => 'racing',
        'user_id' => $userId,
        'state' => [
            'status' => 'racing',
            'seed' => 12345,
            'laps' => 3,
            'finishers' => 0,
            'players' => (object) [],
            'log' => [],
        ],
    ]);
}
