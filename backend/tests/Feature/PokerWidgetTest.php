<?php

use App\Models\Message;
use App\Models\User;
use App\Models\Widget;
use App\Services\Widgets\PokerWidget;
use App\Services\Widgets\WidgetService;
use Laravel\Passport\Passport;

it('turns `h!poker` into a poker widget and a card, not a chat message', function () {
    [$user, , $channel] = ownerWithChannel();
    Passport::actingAs($user);

    $res = $this->postJson("/api/channels/{$channel->id}/messages", [
        'body' => 'h!poker',
    ])->assertCreated();

    expect($res->json('data.type'))->toBe('widget')
        ->and($res->json('data.widget.type'))->toBe('poker')
        ->and(Message::where('body', 'like', 'h!poker%')->exists())->toBeFalse();

    $widget = Widget::where('channel_id', $channel->id)->where('type', 'poker')->sole();
    expect($widget->state['status'])->toBe('idle')
        ->and($widget->state['seats'])->toBe([$user->id])
        ->and($widget->state['players'][(string) $user->id]['chips'])->toBe(1000);
});

it('refuses to deal to a table of one', function () {
    [$user, , $channel] = ownerWithChannel();
    $widget = pokerWidget($channel->id, $user->id);

    $reply = app(WidgetService::class)->handleAction($widget, $user, 'deal', []);

    expect($reply)->toContain('Add a bot')
        ->and($widget->refresh()->state['status'])->toBe('idle');
});

it('lets one player play by filling the other seat with a bot', function () {
    [$user, , $channel] = ownerWithChannel();
    $widget = pokerWidget($channel->id, $user->id);
    $svc = app(WidgetService::class);

    $svc->handleAction($widget, $user, 'join', []);
    $svc->handleAction($widget, $user, 'addbot', []);
    $svc->handleAction($widget, $user, 'deal', []);

    $state = $widget->refresh()->state;
    $bot = collect($state['players'])->firstWhere('bot', true);

    expect($state['status'])->toBe('betting')
        ->and($state['seats'])->toHaveCount(2)
        ->and($bot['cards'])->toHaveCount(2)
        // The bot plays itself the moment the action reaches it, so a lone human always gets
        // the state back with the clock on them (or the hand already over).
        ->and($state['turnId'])->toBeIn([$user->id, null]);
});

it('never shows a bot’s hole cards, even to whoever sat it down', function () {
    [$user, , $channel] = ownerWithChannel();
    $widget = pokerWidget($channel->id, $user->id);
    $svc = app(WidgetService::class);

    $svc->handleAction($widget, $user, 'join', []);
    $svc->handleAction($widget, $user, 'addbot', []);
    $svc->handleAction($widget, $user, 'deal', []);
    $widget->refresh();

    $seen = app(PokerWidget::class)->forViewer($widget, $widget->state, $user);
    $botId = collect($widget->state['players'])->search(fn ($p) => ! empty($p['bot']));

    expect($seen['players'][$botId]['cards'])->each->toBeNull();
});

it('plays a whole hand out against bots without stalling or leaking chips', function () {
    [$user, , $channel] = ownerWithChannel();
    $widget = pokerWidget($channel->id, $user->id);
    $svc = app(WidgetService::class);

    $svc->handleAction($widget, $user, 'join', []);
    $svc->handleAction($widget, $user, 'addbot', []);
    $svc->handleAction($widget, $user, 'addbot', []);
    $svc->handleAction($widget, $user, 'deal', []);

    // Call whatever's in front of us until the hand ends; the bots do the rest.
    for ($i = 0; $i < 30 && $widget->refresh()->state['status'] === 'betting'; $i++) {
        if ($widget->state['turnId'] === $user->id) {
            $svc->handleAction($widget, $user, 'call', []);
        }
    }

    $state = $widget->refresh()->state;
    expect($state['status'])->toBe('showdown')
        ->and($state['pot'])->toBe(0)
        ->and(array_sum(array_map(fn ($p) => $p['chips'], $state['players'])))->toBe(3000);
});

it('sends a bot home again', function () {
    [$user, , $channel] = ownerWithChannel();
    $widget = pokerWidget($channel->id, $user->id);
    $svc = app(WidgetService::class);

    $svc->handleAction($widget, $user, 'join', []);
    $svc->handleAction($widget, $user, 'addbot', []);
    $svc->handleAction($widget, $user, 'removebot', []);

    expect($widget->refresh()->state['seats'])->toBe([$user->id]);
});

it('deals two cards each, posts the blinds and puts the action on someone', function () {
    [$a, , $channel] = ownerWithChannel();
    $b = User::factory()->create();
    $widget = pokerWidget($channel->id, $a->id);
    $svc = app(WidgetService::class);

    $svc->handleAction($widget, $a, 'join', []);
    $svc->handleAction($widget, $b, 'join', []);
    $svc->handleAction($widget, $a, 'deal', []);

    $state = $widget->refresh()->state;
    $stacks = array_map(fn ($p) => $p['chips'], $state['players']);

    expect($state['status'])->toBe('betting')
        ->and($state['stage'])->toBe('preflop')
        ->and($state['board'])->toBe([])
        ->and($state['bet'])->toBe(20)
        ->and($state['deck'])->toHaveCount(48)
        ->and($state['players'][(string) $a->id]['cards'])->toHaveCount(2)
        ->and($state['players'][(string) $b->id]['cards'])->toHaveCount(2)
        // Heads-up: 10 and 20 out of the two stacks, in some order.
        ->and(array_sum($stacks))->toBe(1970)
        ->and($state['turnId'])->toBeIn([$a->id, $b->id]);
});

it('never sends another player the deck or their opponent’s hole cards', function () {
    [$a, , $channel] = ownerWithChannel();
    $b = User::factory()->create();
    $widget = pokerWidget($channel->id, $a->id);
    $svc = app(WidgetService::class);

    $svc->handleAction($widget, $a, 'join', []);
    $svc->handleAction($widget, $b, 'join', []);
    $svc->handleAction($widget, $a, 'deal', []);
    $widget->refresh();

    $seen = app(PokerWidget::class)->forViewer($widget, $widget->state, $a);

    expect($seen)->not->toHaveKey('deck')
        ->and($seen['players'][(string) $a->id]['cards'])->each->toBeString()
        ->and($seen['players'][(string) $b->id]['cards'])->toBe([null, null]);
});

it('refuses an action from someone who isn’t on the clock', function () {
    [$a, , $channel] = ownerWithChannel();
    $b = User::factory()->create();
    $widget = pokerWidget($channel->id, $a->id);
    $svc = app(WidgetService::class);

    $svc->handleAction($widget, $a, 'join', []);
    $svc->handleAction($widget, $b, 'join', []);
    $svc->handleAction($widget, $a, 'deal', []);

    $waiting = $widget->refresh()->state['turnId'] === $a->id ? $b : $a;

    expect($svc->handleAction($widget, $waiting, 'fold', []))->toBe("It's not your turn.");
});

it('gives the pot to the last player standing when everyone folds', function () {
    [$a, , $channel] = ownerWithChannel();
    $b = User::factory()->create();
    $widget = pokerWidget($channel->id, $a->id);
    $svc = app(WidgetService::class);

    $svc->handleAction($widget, $a, 'join', []);
    $svc->handleAction($widget, $b, 'join', []);
    $svc->handleAction($widget, $a, 'deal', []);

    $onClock = $widget->refresh()->state['turnId'] === $a->id ? $a : $b;
    $other = $onClock->is($a) ? $b : $a;

    $svc->handleAction($widget, $onClock, 'fold', []);

    $state = $widget->refresh()->state;
    // The folder loses only what they'd posted; every chip in the hand is accounted for.
    expect($state['status'])->toBe('showdown')
        ->and($state['pot'])->toBe(0)
        ->and($state['players'][(string) $other->id]['chips'] + $state['players'][(string) $onClock->id]['chips'])->toBe(2000)
        ->and($state['players'][(string) $other->id]['chips'])->toBeGreaterThan(1000);
});

it('reads the board and pays the better hand at a showdown', function () {
    [$a, , $channel] = ownerWithChannel();
    $b = User::factory()->create();

    // A rigged river: aces up for A, king-high nothing for B.
    $widget = pokerWidget($channel->id, $a->id, [
        'status' => 'betting',
        'stage' => 'river',
        'handNo' => 1,
        'deck' => [],
        'board' => ['Ks', 'Qd', '7h', '4c', '2s'],
        'pot' => 200,
        'bet' => 0,
        'minRaise' => 20,
        'buttonId' => $a->id,
        'turnId' => $a->id,
        'seats' => [$a->id, $b->id],
        'players' => [
            (string) $a->id => pokerSeat($a->name, chips: 900, committed: 100, cards: ['As', 'Ah']),
            (string) $b->id => pokerSeat($b->name, chips: 900, committed: 100, cards: ['3d', '5c']),
        ],
        'log' => [],
    ]);

    $svc = app(WidgetService::class);
    $svc->handleAction($widget, $a, 'check', []);
    $svc->handleAction($widget, $b, 'check', []);

    $state = $widget->refresh()->state;
    expect($state['status'])->toBe('showdown')
        ->and($state['players'][(string) $a->id]['chips'])->toBe(1100)
        ->and($state['players'][(string) $b->id]['chips'])->toBe(900)
        ->and($state['players'][(string) $a->id]['hand'])->toBe('Pair');
});

it('cuts a side pot so a short all-in can only win what it covered', function () {
    [$short, , $channel] = ownerWithChannel();
    $b = User::factory()->create();
    $c = User::factory()->create();

    // The short stack has the best hand (trip kings) but was all-in for 40; B's aces beat
    // C's queens for everything above that.
    $widget = pokerWidget($channel->id, $short->id, [
        'status' => 'betting',
        'stage' => 'river',
        'handNo' => 1,
        'deck' => [],
        'board' => ['Kd', 'Qh', '7h', '4c', '2s'],
        'pot' => 440,
        'bet' => 0,
        'minRaise' => 20,
        'buttonId' => $short->id,
        'turnId' => $b->id,
        'seats' => [$short->id, $b->id, $c->id],
        'players' => [
            (string) $short->id => pokerSeat($short->name, chips: 0, committed: 40, cards: ['Ks', 'Kh'], allIn: true),
            (string) $b->id => pokerSeat($b->name, chips: 800, committed: 200, cards: ['Ad', 'Ac']),
            (string) $c->id => pokerSeat($c->name, chips: 800, committed: 200, cards: ['Qs', 'Jc']),
        ],
        'log' => [],
    ]);

    $svc = app(WidgetService::class);
    $svc->handleAction($widget, $b, 'check', []);
    $svc->handleAction($widget, $c, 'check', []);

    $state = $widget->refresh()->state;
    // Main pot: 40 × 3 = 120 to the kings. Side pot: 160 × 2 = 320 to the aces.
    expect($state['players'][(string) $short->id]['chips'])->toBe(120)
        ->and($state['players'][(string) $b->id]['chips'])->toBe(1120)
        ->and($state['players'][(string) $c->id]['chips'])->toBe(800);
});

/** @param  array<string, mixed>|null  $state */
function pokerWidget(int $channelId, int $userId, ?array $state = null): Widget
{
    return Widget::create([
        'channel_id' => $channelId,
        'type' => 'poker',
        'user_id' => $userId,
        'state' => $state ?? app(PokerWidget::class)->initialState(),
    ]);
}

/** One seated player, mid-hand. @param array<int, string> $cards */
function pokerSeat(string $name, int $chips, int $committed, array $cards, bool $allIn = false): array
{
    return [
        'name' => $name, 'chips' => $chips, 'bet' => 0, 'committed' => $committed,
        'folded' => false, 'allIn' => $allIn, 'acted' => false, 'cards' => $cards,
        'hand' => null, 'won' => 0,
    ];
}
