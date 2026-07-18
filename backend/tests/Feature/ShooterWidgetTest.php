<?php

use App\Models\Message;
use App\Models\User;
use App\Models\Widget;
use App\Services\Widgets\WidgetService;
use Laravel\Passport\Passport;

it('turns `g!raid` into a shooter widget and a card, not a chat message', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $res = $this->postJson("/api/channels/{$channel->id}/messages", [
        'body' => 'g!raid',
    ])->assertCreated();

    expect($res->json('data.type'))->toBe('widget')
        ->and($res->json('data.widget.type'))->toBe('shooter')
        ->and(Message::where('body', 'like', 'g!raid%')->exists())->toBeFalse();

    $widget = Widget::where('channel_id', $channel->id)->where('type', 'shooter')->sole();
    expect($widget->state['status'])->toBe('active')
        ->and($widget->state['wave'])->toBe(1)
        ->and($widget->state['seed'])->toBeGreaterThan(0)
        ->and($widget->state['teamLives'])->toBe($widget->state['maxLives'])
        ->and($widget->state['players'])->toBeEmpty();
});

it('enrols a player on join', function () {
    [$user, , $channel] = ownerWithChannel();
    $widget = raidWidget($channel->id, $user->id);

    app(WidgetService::class)->handleAction($widget, $user, 'join', []);

    $widget->refresh();
    expect($widget->state['players'][(string) $user->id]['name'])->toBe($user->name)
        ->and($widget->state['players'][(string) $user->id]['kills'])->toBe(0);
});

it('pools batched frags into the score and the leaderboard, clamped', function () {
    [$user, , $channel] = ownerWithChannel();
    $widget = raidWidget($channel->id, $user->id);
    $svc = app(WidgetService::class);

    $svc->handleAction($widget, $user, 'frag', ['kills' => 3, 'points' => 300]);
    $svc->handleAction($widget, $user, 'frag', ['kills' => 2, 'points' => 200]);
    // Absurd values are clamped, not trusted.
    $svc->handleAction($widget, $user, 'frag', ['kills' => 9999, 'points' => 9_999_999]);

    $widget->refresh();
    expect($widget->state['players'][(string) $user->id]['kills'])->toBe(3 + 2 + 100)
        ->and($widget->state['score'])->toBe(300 + 200 + 100_000);
});

it('advances the shared wave only forward (whoever clears first wins the max)', function () {
    [$user, , $channel] = ownerWithChannel();
    $widget = raidWidget($channel->id, $user->id);
    $svc = app(WidgetService::class);

    $svc->handleAction($widget, $user, 'wave', ['wave' => 3]);
    $svc->handleAction($widget, $user, 'wave', ['wave' => 2]); // a straggler — ignored

    $widget->refresh();
    expect($widget->state['wave'])->toBe(3);
});

it('spends a shared life per death and wipes the team at zero', function () {
    [$user, , $channel] = ownerWithChannel();
    $mate = User::factory()->create();
    $widget = raidWidget($channel->id, $user->id);
    $svc = app(WidgetService::class);

    $lives = $widget->state['maxLives'];
    for ($i = 0; $i < $lives - 1; $i++) {
        $svc->handleAction($widget, $user, 'died', []);
    }
    $widget->refresh();
    expect($widget->state['status'])->toBe('active')->and($widget->state['teamLives'])->toBe(1);

    $svc->handleAction($widget, $mate, 'died', []);
    $widget->refresh();
    expect($widget->state['teamLives'])->toBe(0)->and($widget->state['status'])->toBe('lost');
});

/** A fresh, active wave-1 raid to act on. */
function raidWidget(int $channelId, int $userId): Widget
{
    return Widget::create([
        'channel_id' => $channelId,
        'type' => 'shooter',
        'user_id' => $userId,
        'state' => [
            'status' => 'active',
            'wave' => 1,
            'seed' => 12345,
            'score' => 0,
            'teamLives' => 6,
            'maxLives' => 6,
            'players' => (object) [],
            'log' => [],
        ],
    ]);
}
