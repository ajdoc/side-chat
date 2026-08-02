<?php

namespace App\Services\Widgets;

use App\Models\User;
use App\Models\Widget;
use App\Support\Commands\ParsedCommand;

/**
 * "Side Poker" — a real table of no-limit Texas Hold'em, dealt and refereed here.
 *
 * The other games in the catalogue ({@see ShooterWidget}, {@see RacingWidget}) keep only a
 * scoreboard and let the client run the game; poker can't work that way. The deck is the
 * whole game: a client that knows the shuffle knows everyone's hand, and a client that
 * decides who won will decide it wrongly the moment somebody wants it to. So *all* of it
 * lives here — the shuffle, the blinds, whose turn it is, what a raise is allowed to be,
 * which five cards make the best hand and who the pot belongs to. The card
 * (frontend/app/components/PokerTable.vue) draws state and posts button presses; it rules
 * on nothing.
 *
 * Two things are secret rather than merely unshown, so this is a {@see RedactsState}:
 * the undealt `deck`, and every player's hole cards but your own. They're stripped on the
 * way out per viewer ({@see forViewer}), which works because a `WidgetUpdated` broadcast
 * carries only a reference — each client then fetches the state *it* is entitled to.
 *
 * Side pots are honest too: a short stack that's all-in for 40 can only win the part of the
 * pot it could match, so the pot is settled in layers by how much each player put in over
 * the hand ({@see settle}) rather than handed whole to the best hand.
 *
 * State shape:
 *   status:    'idle' | 'betting' | 'showdown'
 *   stage:     'preflop' | 'flop' | 'turn' | 'river'
 *   handNo:    int                    — hands dealt at this table
 *   deck:      [ "As", … ]            — never leaves the server
 *   board:     [ "As", … ]            — community cards, 0/3/4/5 of them
 *   pot:       int                    — chips already collected from finished streets
 *   bet:       int                    — the amount to match on this street
 *   minRaise:  int                    — smallest legal raise increment right now
 *   buttonId:  int|null               — the dealer button; blinds and turn order follow it
 *   turnId:    int|null               — whose decision the table is waiting on
 *   seats:     [ userId, … ]          — seating order, clockwise
 *   players:   { "<userId>": { name, chips, bet, committed, folded, allIn, acted, cards, hand, won } }
 *   log:       [ string, … ]          — last few events, newest last
 */
final class PokerWidget implements RedactsState, WidgetHandler
{
    /** What the house stakes a new (or busted) player — poker with no chips isn't poker. */
    private const BUY_IN = 1000;

    private const SMALL_BLIND = 10;

    private const BIG_BLIND = 20;

    private const MAX_SEATS = 8;

    /**
     * House bots, so one person can play.
     *
     * A bot is a *seat*, not an account: it has no User row, and its id is negative so it can
     * never collide with a real user's — which also means `forViewer` hides its hole cards from
     * everyone by the existing rule ("not you, not shown at a showdown"), with nothing added for
     * bots specifically. Nobody, including the person who added it, can see what it's holding.
     */
    private const BOT_NAMES = ['Chip', 'Ace', 'Bluff', 'Dice', 'Rounder', 'Nickel', 'Slick'];

    /** How many bot turns to play out in one go before assuming something's wrong and stopping. */
    private const BOT_TURN_LIMIT = 60;

    private const LOG_MAX = 8;

    private const RANKS = '23456789TJQKA';

    private const SUITS = 'shdc';

    /** Category scores, worst to best — the top half of a hand's comparable value. */
    private const HAND_NAMES = [
        0 => 'High card', 1 => 'Pair', 2 => 'Two pair', 3 => 'Three of a kind', 4 => 'Straight',
        5 => 'Flush', 6 => 'Full house', 7 => 'Four of a kind', 8 => 'Straight flush',
    ];

    public function type(): string
    {
        return 'poker';
    }

    public function initialState(): array
    {
        return [
            'status' => 'idle',
            'stage' => 'preflop',
            'handNo' => 0,
            'deck' => [],
            'board' => [],
            'pot' => 0,
            'bet' => 0,
            'minRaise' => self::BIG_BLIND,
            'buttonId' => null,
            'turnId' => null,
            'seats' => [],
            'players' => (object) [],
            'log' => ['🃏 Table open — take a seat.'],
        ];
    }

    /**
     * What this viewer may see. The deck is nobody's business; hole cards are their owner's
     * alone until a showdown puts them face up — and a hand that folded never shows.
     */
    public function forViewer(Widget $widget, array $state, ?User $viewer): array
    {
        unset($state['deck']);

        $showdown = ($state['status'] ?? '') === 'showdown';
        $players = (array) ($state['players'] ?? []);

        foreach ($players as $pid => $player) {
            $isMine = $viewer !== null && (string) $viewer->id === (string) $pid;
            $shown = $showdown && empty($player['folded']);
            if (! $isMine && ! $shown) {
                // Kept as a count, not dropped: the card still needs to draw two face-down
                // cards for a player who's holding, and none for one who isn't in the hand.
                $players[$pid]['cards'] = array_fill(0, count((array) ($player['cards'] ?? [])), null);
            }
        }
        $state['players'] = $players;

        return $state;
    }

    public function command(Widget $widget, User $user, ParsedCommand $command): WidgetOutcome
    {
        return match ($command->verb) {
            'poker', 'play', 'table', 'show' => $this->open($widget, $user),
            'deal', 'start', 'go', 'next' => $this->deal($widget, $user),
            'join', 'sit' => $this->apply($this->join($widget, $user), card: true),
            'leave', 'stand' => $this->leave($widget, $user),
            'fold' => $this->act($widget, $user->id, 'fold', []),
            'check' => $this->act($widget, $user->id, 'check', []),
            'call' => $this->act($widget, $user->id, 'call', []),
            'bet', 'raise' => $this->act($widget, $user->id, 'raise', ['amount' => (int) $command->args]),
            'allin' => $this->act($widget, $user->id, 'allin', []),
            'bot', 'bots' => $this->apply($this->addBot($widget), card: true),
            'reset', 'rebuy' => $this->reset($widget),
            'help', 'h' => WidgetOutcome::reply($this->help()),
            default => WidgetOutcome::reply("Unknown poker command `h!{$command->verb}`. Try `h!help`."),
        };
    }

    public function action(Widget $widget, User $user, string $action, array $payload): WidgetOutcome
    {
        return match ($action) {
            'join' => $this->join($widget, $user),
            'leave' => $this->leaveQuietly($widget, $user),
            'deal' => $this->apply($this->deal($widget, $user), card: false),
            'fold', 'check', 'call', 'raise', 'allin' => $this->act($widget, $user->id, $action, $payload),
            'addbot' => $this->addBot($widget),
            'removebot' => $this->removeBot($widget),
            'reset' => $this->apply($this->reset($widget), card: false),
            default => WidgetOutcome::noop(),
        };
    }

    /** `h!poker` — surface the table, seating the caller if there's room. */
    private function open(Widget $widget, User $user): WidgetOutcome
    {
        $this->join($widget, $user);

        return WidgetOutcome::card();
    }

    /** A command wants a card in the timeline where an action wants a quiet patch. */
    private function apply(WidgetOutcome $outcome, bool $card): WidgetOutcome
    {
        if (! $card || $outcome->reply !== null) {
            return $outcome;
        }

        return $outcome->changed ? WidgetOutcome::card() : WidgetOutcome::show();
    }

    // --- seating -------------------------------------------------------------------

    private function join(Widget $widget, User $user): WidgetOutcome
    {
        $state = $widget->state;
        $pid = (string) $user->id;
        $players = (array) $state['players'];

        if (isset($players[$pid])) {
            return WidgetOutcome::noop();
        }
        if (count($state['seats']) >= self::MAX_SEATS) {
            return WidgetOutcome::reply('That table is full — '.self::MAX_SEATS.' seats is the lot.');
        }

        $players[$pid] = $this->freshPlayer($user->name);
        $state['players'] = $players;
        $state['seats'] = [...$state['seats'], (int) $user->id];
        $state['log'] = $this->pushLog($state['log'], "🪑 {$user->name} sat down with ".self::BUY_IN.' chips');
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function leave(Widget $widget, User $user): WidgetOutcome
    {
        return $this->apply($this->leaveQuietly($widget, $user), card: true);
    }

    /**
     * Stand up. Mid-hand this is a fold first — the chips they've already put in stay in the
     * pot, which is exactly what walking away from a live hand means at a real table.
     */
    private function leaveQuietly(Widget $widget, User $user): WidgetOutcome
    {
        $state = $widget->state;
        $pid = (string) $user->id;
        if (! isset($state['players'][$pid])) {
            return WidgetOutcome::noop();
        }

        if ($state['status'] === 'betting' && empty($state['players'][$pid]['folded'])) {
            $this->act($widget, $user->id, 'fold', []);
            $state = $widget->state;
        }

        $players = (array) $state['players'];
        unset($players[$pid]);
        $state['players'] = $players;
        $state['seats'] = array_values(array_filter($state['seats'], fn ($id) => (int) $id !== $user->id));
        if ((int) $state['buttonId'] === $user->id) {
            $state['buttonId'] = $state['seats'][0] ?? null;
        }
        $state['log'] = $this->pushLog($state['log'], "🚪 {$user->name} left the table");
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    // --- dealing -------------------------------------------------------------------

    /**
     * Deal a hand: move the button, shuffle, post the blinds, two cards each.
     *
     * Anyone sitting broke is restaked here rather than quietly frozen out — this is a chat
     * game, and a seat that can never play again is just a dead row on the card.
     */
    private function deal(Widget $widget, User $user): WidgetOutcome
    {
        $state = $widget->state;
        $this->join($widget, $user);
        $state = $widget->state;

        if ($state['status'] === 'betting') {
            return WidgetOutcome::reply('There\'s a hand in progress — play it out first.');
        }
        if (count($state['seats']) < 2) {
            return WidgetOutcome::reply('Poker needs two — press **Add a bot** to fill the seat, or get someone else to sit down.');
        }

        $seats = array_values($state['seats']);
        $players = (array) $state['players'];
        foreach ($players as $pid => $player) {
            $players[$pid] = $this->freshHand($player);
            if ($players[$pid]['chips'] <= 0) {
                $players[$pid]['chips'] = self::BUY_IN;
            }
        }

        $deck = $this->shuffled();
        foreach ($seats as $id) {
            $players[(string) $id]['cards'] = [array_pop($deck), array_pop($deck)];
        }

        $state['handNo'] = (int) $state['handNo'] + 1;
        $state['status'] = 'betting';
        $state['stage'] = 'preflop';
        $state['deck'] = $deck;
        $state['board'] = [];
        $state['pot'] = 0;
        $state['bet'] = 0;
        $state['minRaise'] = self::BIG_BLIND;
        $state['buttonId'] = $this->nextSeat($seats, $state['buttonId']);
        $state['players'] = $players;
        $state['log'] = $this->pushLog($state['log'], "🃏 Hand #{$state['handNo']} — cards in the air");
        $widget->state = $state;

        $this->postBlinds($widget);
        $this->runBots($widget);

        return WidgetOutcome::card();
    }

    /**
     * Small and big blind, then the action.
     *
     * Heads-up the button *is* the small blind and acts first pre-flop; at a fuller table the
     * blinds are the two seats after it and the action starts one further round. Both are the
     * real rule, and getting it wrong is the kind of thing poker players notice immediately.
     */
    private function postBlinds(Widget $widget): void
    {
        $state = $widget->state;
        $seats = array_values($state['seats']);
        $heads = count($seats) === 2;

        $sbId = $heads ? (int) $state['buttonId'] : $this->nextSeat($seats, $state['buttonId']);
        $bbId = $this->nextSeat($seats, $sbId);

        $widget->state = $state;
        $this->putIn($widget, $sbId, self::SMALL_BLIND);
        $this->putIn($widget, $bbId, self::BIG_BLIND);

        $state = $widget->state;
        $state['bet'] = self::BIG_BLIND;
        $state['minRaise'] = self::BIG_BLIND;
        // The blinds are forced, not decisions: both still owe the table an action, and the
        // big blind keeps its option to raise its own blind when the bet comes back round.
        $state['players'][(string) $sbId]['acted'] = false;
        $state['players'][(string) $bbId]['acted'] = false;
        $state['turnId'] = $this->nextActive($state, $bbId);
        $state['log'] = $this->pushLog(
            $state['log'],
            "💰 {$state['players'][(string) $sbId]['name']} posts ".self::SMALL_BLIND.", {$state['players'][(string) $bbId]['name']} posts ".self::BIG_BLIND
        );
        $widget->state = $state;
    }

    // --- the betting round ---------------------------------------------------------

    /**
     * One player's decision. Everything that can be illegal is refused here, not on the card.
     *
     * Takes a bare id rather than a {@see User} because a bot is a seat without an account
     * (see {@see addBot}) and must go through exactly this path — same legality checks, same
     * pot maths — rather than a private back door that could drift from the real rules.
     */
    private function act(Widget $widget, int $actorId, string $action, array $payload): WidgetOutcome
    {
        $state = $widget->state;
        $pid = (string) $actorId;

        if ($state['status'] !== 'betting' || ! isset($state['players'][$pid])) {
            return WidgetOutcome::noop();
        }
        if ((int) $state['turnId'] !== $actorId) {
            return WidgetOutcome::reply("It's not your turn.");
        }

        $player = $state['players'][$pid];
        $owed = max(0, (int) $state['bet'] - (int) $player['bet']);

        switch ($action) {
            case 'fold':
                $state['players'][$pid]['folded'] = true;
                $state['log'] = $this->pushLog($state['log'], "🚫 {$player['name']} folds");
                break;

            case 'check':
                if ($owed > 0) {
                    return WidgetOutcome::reply("You can't check — there's {$owed} to call.");
                }
                $state['log'] = $this->pushLog($state['log'], "✅ {$player['name']} checks");
                break;

            case 'call':
                if ($owed === 0) {
                    $state['log'] = $this->pushLog($state['log'], "✅ {$player['name']} checks");
                    break;
                }
                $paid = min($owed, (int) $player['chips']);
                $widget->state = $state;
                $this->putIn($widget, $actorId, $paid);
                $state = $widget->state;
                $state['log'] = $this->pushLog($state['log'], "📞 {$player['name']} calls {$paid}");
                break;

            case 'allin':
            case 'raise':
                $chips = (int) $player['chips'];
                // `amount` is the total this player is betting *to* on this street, the way a
                // table calls a raise ("raise to 200"), not the extra on top.
                $to = $action === 'allin'
                    ? (int) $player['bet'] + $chips
                    : (int) ($payload['amount'] ?? 0);
                $extra = $to - (int) $player['bet'];

                if ($extra <= 0 || $extra > $chips) {
                    return WidgetOutcome::reply('That bet is more than you have in front of you.');
                }
                $isAllIn = $extra === $chips;
                $minTo = (int) $state['bet'] + (int) $state['minRaise'];
                if (! $isAllIn && $to < $minTo) {
                    return WidgetOutcome::reply("A raise has to make it at least {$minTo}.");
                }

                $widget->state = $state;
                $this->putIn($widget, $actorId, $extra);
                $state = $widget->state;

                if ($to > (int) $state['bet']) {
                    // A short all-in that doesn't clear a full raise doesn't reopen the betting
                    // for players who've already acted, so minRaise only grows on a real raise.
                    $state['minRaise'] = max((int) $state['minRaise'], $to - (int) $state['bet']);
                    $state['bet'] = $to;
                    $state['players'] = $this->reopen($state['players'], $pid);
                    $verb = $owed > 0 ? 'raises to' : 'bets';
                    $state['log'] = $this->pushLog($state['log'], "🔺 {$player['name']} {$verb} {$to}".($isAllIn ? ' — all in!' : ''));
                } else {
                    $state['log'] = $this->pushLog($state['log'], "🔺 {$player['name']} is all in for {$to}");
                }
                break;

            default:
                return WidgetOutcome::noop();
        }

        $state['players'][$pid]['acted'] = true;
        $widget->state = $state;

        $this->advance($widget);
        // Whoever's next may be a bot — and the one after that. Play them out before answering,
        // so the human gets back a state where the clock is on them again.
        $this->runBots($widget);

        return WidgetOutcome::updated();
    }

    /**
     * Move the hand on: next player, next street, or the end of it.
     *
     * The three ways a hand stops needing decisions all meet here — everybody folded, everybody
     * left is all-in (so the rest of the board is a formality), or the street's bets are square.
     */
    private function advance(Widget $widget): void
    {
        $state = $widget->state;
        $live = $this->livePlayers($state);

        if (count($live) <= 1) {
            $this->settle($widget, uncontested: true);

            return;
        }

        // Anyone who still has chips and hasn't folded owes the table an action.
        $pending = array_filter($live, fn ($p) => empty($p['allIn']) && (empty($p['acted']) || (int) $p['bet'] < (int) $state['bet']));

        if ($pending !== []) {
            $state['turnId'] = $this->nextActive($state, (int) $state['turnId']);
            $widget->state = $state;

            return;
        }

        $this->collect($widget);

        // Nobody left who can act — deal the rest of the board out and show the cards.
        $canAct = array_filter($this->livePlayers($widget->state), fn ($p) => empty($p['allIn']));
        if (count($canAct) <= 1) {
            while (count($widget->state['board']) < 5) {
                $this->burnAndTurn($widget);
            }
            $this->settle($widget, uncontested: false);

            return;
        }

        if ($widget->state['stage'] === 'river') {
            $this->settle($widget, uncontested: false);

            return;
        }

        $this->burnAndTurn($widget);

        $state = $widget->state;
        $state['bet'] = 0;
        $state['minRaise'] = self::BIG_BLIND;
        foreach ($state['players'] as $pid => $player) {
            $state['players'][$pid]['bet'] = 0;
            $state['players'][$pid]['acted'] = false;
        }
        // Post-flop the action starts left of the button, not left of the last raiser.
        $state['turnId'] = $this->nextActive($state, (int) $state['buttonId']);
        $widget->state = $state;
    }

    /** Turn the next street: flop (three), turn (one), river (one). */
    private function burnAndTurn(Widget $widget): void
    {
        $state = $widget->state;
        $deck = (array) $state['deck'];
        $board = (array) $state['board'];

        $count = $board === [] ? 3 : 1;
        for ($i = 0; $i < $count; $i++) {
            $board[] = array_pop($deck);
        }

        $state['board'] = $board;
        $state['deck'] = $deck;
        $state['stage'] = match (count($board)) {
            3 => 'flop', 4 => 'turn', default => 'river'
        };
        $state['log'] = $this->pushLog($state['log'], strtoupper($state['stage']).': '.implode(' ', array_map([$this, 'pretty'], $board)));
        $widget->state = $state;
    }

    /** Sweep the street's bets into the pot. `committed` keeps the per-hand total side pots need. */
    private function collect(Widget $widget): void
    {
        $state = $widget->state;
        foreach ($state['players'] as $pid => $player) {
            $state['pot'] = (int) $state['pot'] + (int) $player['bet'];
            $state['players'][$pid]['bet'] = 0;
        }
        $widget->state = $state;
    }

    // --- the showdown --------------------------------------------------------------

    /**
     * Award the pot and put the hand to bed.
     *
     * Settled in layers rather than in one lump: each distinct amount somebody committed marks
     * a pot that only the players who matched it can win. That's what stops a player all-in for
     * 40 from scooping 400 off two stacks they were never covering.
     */
    private function settle(Widget $widget, bool $uncontested): void
    {
        $this->collect($widget);
        $state = $widget->state;
        $players = (array) $state['players'];

        $scores = [];
        foreach ($players as $pid => $player) {
            if (! empty($player['folded']) || $player['cards'] === []) {
                continue;
            }
            if (! $uncontested) {
                [$score, $name] = $this->best([...(array) $player['cards'], ...(array) $state['board']]);
                $players[$pid]['hand'] = $name;
                $scores[$pid] = $score;
            } else {
                $scores[$pid] = 0;
            }
        }

        // Every level somebody's contribution stops at is the top of one pot.
        $levels = array_values(array_unique(array_filter(array_map(fn ($p) => (int) $p['committed'], $players))));
        sort($levels);

        $previous = 0;
        $awarded = [];
        foreach ($levels as $level) {
            $amount = 0;
            foreach ($players as $player) {
                $amount += max(0, min((int) $player['committed'], $level) - $previous);
            }
            $previous = $level;

            $eligible = array_keys(array_filter(
                $scores,
                fn ($pid) => (int) $players[$pid]['committed'] >= $level,
                ARRAY_FILTER_USE_KEY
            ));
            if ($eligible === [] || $amount === 0) {
                continue;
            }

            $top = max(array_map(fn ($pid) => $scores[$pid], $eligible));
            $winners = array_values(array_filter($eligible, fn ($pid) => $scores[$pid] === $top));
            $share = intdiv($amount, count($winners));
            // The odd chip goes to the first winner clockwise from the button, as at a real table.
            $odd = $amount - $share * count($winners);

            foreach ($winners as $i => $pid) {
                $take = $share + ($i === 0 ? $odd : 0);
                $players[$pid]['chips'] = (int) $players[$pid]['chips'] + $take;
                $players[$pid]['won'] = (int) $players[$pid]['won'] + $take;
                $awarded[$pid] = ($awarded[$pid] ?? 0) + $take;
            }
        }

        foreach (array_keys($awarded) as $pid) {
            $hand = $uncontested ? 'everyone else folded' : $players[$pid]['hand'];
            $state['log'] = $this->pushLog($state['log'], "🏆 {$players[$pid]['name']} wins {$awarded[$pid]} — {$hand}");
        }

        $state['players'] = $players;
        $state['pot'] = 0;
        $state['status'] = 'showdown';
        $state['turnId'] = null;
        $state['deck'] = [];
        $widget->state = $state;
    }

    // --- hand evaluation -----------------------------------------------------------

    /**
     * The best five of seven, as a single comparable integer and its English name.
     *
     * The score is the category in the high digits and the ranks that break ties beneath it,
     * base 16 — so a straight beats two pair by construction and two flushes compare card by
     * card, with no special cases at the call site.
     *
     * @param  array<int, string>  $cards
     * @return array{0: int, 1: string}
     */
    private function best(array $cards): array
    {
        $bestScore = -1;
        $bestCategory = 0;
        $n = count($cards);

        for ($a = 0; $a < $n - 4; $a++) {
            for ($b = $a + 1; $b < $n - 3; $b++) {
                for ($c = $b + 1; $c < $n - 2; $c++) {
                    for ($d = $c + 1; $d < $n - 1; $d++) {
                        for ($e = $d + 1; $e < $n; $e++) {
                            [$score, $category] = $this->score([$cards[$a], $cards[$b], $cards[$c], $cards[$d], $cards[$e]]);
                            if ($score > $bestScore) {
                                $bestScore = $score;
                                $bestCategory = $category;
                            }
                        }
                    }
                }
            }
        }

        return [$bestScore, self::HAND_NAMES[$bestCategory]];
    }

    /**
     * Score exactly five cards.
     *
     * @param  array<int, string>  $cards
     * @return array{0: int, 1: int} score, category
     */
    private function score(array $cards): array
    {
        $ranks = array_map(fn ($card) => strpos(self::RANKS, $card[0]) + 2, $cards);
        $suits = array_map(fn ($card) => $card[1], $cards);

        $counts = array_count_values($ranks);
        // Sort by how many of a rank there are first, then by the rank — which is exactly the
        // order the tiebreakers are read in for every category (trips before the kickers, the
        // higher pair before the lower).
        uksort($counts, fn ($x, $y) => [$counts[$y], $y] <=> [$counts[$x], $x]);
        $ordered = array_keys($counts);
        $shape = array_values($counts);

        $flush = count(array_unique($suits)) === 1;
        $unique = $ordered;
        rsort($unique);
        $straight = count($unique) === 5 && $unique[0] - $unique[4] === 4;
        // The wheel: A-2-3-4-5, where the ace plays low and the hand is a five-high straight.
        $wheel = $unique === [14, 5, 4, 3, 2];
        if ($wheel) {
            $straight = true;
            $ordered = [5, 4, 3, 2, 1];
        }

        $category = match (true) {
            $straight && $flush => 8,
            $shape === [4, 1] => 7,
            $shape === [3, 2] => 6,
            $flush => 5,
            $straight => 4,
            $shape === [3, 1, 1] => 3,
            $shape === [2, 2, 1] => 2,
            $shape === [2, 1, 1, 1] => 1,
            default => 0,
        };

        // Padded to a fixed five slots first: a hand with four distinct ranks would otherwise
        // shift one place fewer than a high card and come out smaller than the hand it beats.
        $ordered = array_pad($ordered, 5, 0);

        $score = $category;
        foreach ($ordered as $rank) {
            $score = $score * 16 + $rank;
        }

        return [$score, $category];
    }

    // --- the house bots ------------------------------------------------------------

    /** Seat a bot. Named from a short list, and numbered if the table's been round it once. */
    private function addBot(Widget $widget): WidgetOutcome
    {
        $state = $widget->state;
        if (count($state['seats']) >= self::MAX_SEATS) {
            return WidgetOutcome::reply('That table is full — '.self::MAX_SEATS.' seats is the lot.');
        }

        $players = (array) $state['players'];
        $bots = array_filter($players, fn ($p) => ! empty($p['bot']));
        // Ids walk downwards from -1; the lowest one in use tells us the next free one.
        $id = min([0, ...array_map('intval', array_keys($players))]) - 1;
        $name = self::BOT_NAMES[count($bots) % count(self::BOT_NAMES)];
        if (count($bots) >= count(self::BOT_NAMES)) {
            $name .= ' '.(intdiv(count($bots), count(self::BOT_NAMES)) + 1);
        }

        $players[(string) $id] = [...$this->freshPlayer($name), 'bot' => true];
        $state['players'] = $players;
        $state['seats'] = [...$state['seats'], $id];
        $state['log'] = $this->pushLog($state['log'], "🤖 {$name} pulled up a chair");
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    /** Send the last-seated bot home. Mid-hand it folds first, like anyone else standing up. */
    private function removeBot(Widget $widget): WidgetOutcome
    {
        $state = $widget->state;
        $bots = array_keys(array_filter((array) $state['players'], fn ($p) => ! empty($p['bot'])));
        if ($bots === []) {
            return WidgetOutcome::noop();
        }

        $id = (int) end($bots);
        if ($state['status'] === 'betting' && empty($state['players'][(string) $id]['folded'])) {
            if ((int) $state['turnId'] === $id) {
                $this->act($widget, $id, 'fold', []);
            } else {
                $state['players'][(string) $id]['folded'] = true;
                $widget->state = $state;
            }
            $state = $widget->state;
        }

        $name = $state['players'][(string) $id]['name'];
        $players = (array) $state['players'];
        unset($players[(string) $id]);
        $state['players'] = $players;
        $state['seats'] = array_values(array_filter($state['seats'], fn ($seat) => (int) $seat !== $id));
        if ((int) $state['buttonId'] === $id) {
            $state['buttonId'] = $state['seats'][0] ?? null;
        }
        $state['log'] = $this->pushLog($state['log'], "🤖 {$name} cashed out");
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    /**
     * Play out every bot that's on the clock, back to back, until a human owes a decision.
     *
     * Done synchronously inside the request that put a bot on the clock, rather than on a queue:
     * one `WidgetUpdated` then carries the whole run of bot play, and the state a client fetches
     * is never a half-finished hand waiting on a job. The turn limit is a guard against a
     * mistake in the betting logic spinning here forever, not an expected path.
     */
    private function runBots(Widget $widget): void
    {
        for ($i = 0; $i < self::BOT_TURN_LIMIT; $i++) {
            $state = $widget->state;
            $turn = $state['turnId'];
            if ($state['status'] !== 'betting' || $turn === null || empty($state['players'][(string) $turn]['bot'])) {
                return;
            }

            [$action, $payload] = $this->botDecision($state, (int) $turn);
            $this->act($widget, (int) $turn, $action, $payload);
        }
    }

    /**
     * What a bot does with the hand it's been given.
     *
     * Not a solver and not trying to be — it's an opponent for a chat game. It scores its hand
     * from 0 to 1 ({@see botStrength}), compares that against what the table is charging it to
     * stay in (the pot odds), and raises when it's genuinely strong, with a thin slice of
     * bluffs so that betting into it isn't a free roll. The randomness is what stops it being
     * readable after three hands.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function botDecision(array $state, int $botId): array
    {
        $player = $state['players'][(string) $botId];
        $strength = $this->botStrength((array) $player['cards'], (array) $state['board']);
        $owed = max(0, (int) $state['bet'] - (int) $player['bet']);
        $chips = (int) $player['chips'];
        $pot = (int) $state['pot'] + array_sum(array_map(fn ($p) => (int) $p['bet'], (array) $state['players']));

        // A little noise in both directions, so the same hand isn't always played the same way.
        $strength = max(0.0, min(1.0, $strength + random_int(-8, 8) / 100));
        $bluffing = random_int(1, 100) <= 7;

        if ($owed === 0) {
            if ($strength > 0.62 || $bluffing) {
                $to = (int) $player['bet'] + max((int) $state['minRaise'], (int) round($pot * ($strength > 0.85 ? 0.75 : 0.45)));

                return $this->botRaise($state, $player, $to);
            }

            return ['check', []];
        }

        // What this call costs relative to what it could win — the number a folding decision
        // actually turns on, rather than the raw size of the bet.
        $potOdds = $owed / max(1, $pot + $owed);

        if ($strength > 0.8 && $owed < $chips) {
            $to = (int) $state['bet'] + max((int) $state['minRaise'], (int) round($pot * 0.6));

            return $this->botRaise($state, $player, $to);
        }
        if ($strength >= $potOdds + 0.08 || ($bluffing && $owed <= $chips * 0.15)) {
            return ['call', []];
        }

        return ['fold', []];
    }

    /**
     * A raise clamped to what the bot can actually make — and turned into an all-in when what
     * it wants to bet is everything it has, which is the only way `act` accepts a short raise.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function botRaise(array $state, array $player, int $to): array
    {
        $max = (int) $player['bet'] + (int) $player['chips'];
        $minTo = (int) $state['bet'] + (int) $state['minRaise'];

        if ($to >= $max || $minTo >= $max) {
            return ['allin', []];
        }

        return ['raise', ['amount' => max($minTo, min($to, $max))]];
    }

    /**
     * How good this hand looks, from 0 (nothing) to 1 (the nuts, as far as it can tell).
     *
     * Pre-flop there's no board to read, so it's the usual shape of a starting hand: pairs,
     * high cards, suited, connected. From the flop on it's the made hand off all seven cards,
     * which is the same {@see best} the showdown uses — the bot is reading the board exactly
     * as it will eventually be scored, just without any idea what anyone else holds.
     *
     * @param  array<int, string>  $cards
     * @param  array<int, string>  $board
     */
    private function botStrength(array $cards, array $board): float
    {
        if (count($cards) < 2) {
            return 0.0;
        }

        $rank = fn (string $card) => strpos(self::RANKS, $card[0]) + 2;

        if ($board === []) {
            [$high, $low] = [max($rank($cards[0]), $rank($cards[1])), min($rank($cards[0]), $rank($cards[1]))];
            $score = ($high - 2) / 24 + ($low - 2) / 40;
            if ($high === $low) {
                $score += 0.35;                                  // a pocket pair plays itself
            }
            if ($cards[0][1] === $cards[1][1]) {
                $score += 0.06;                                  // suited
            }
            if ($high - $low === 1) {
                $score += 0.05;                                  // connected
            }

            return min(1.0, $score);
        }

        [, $name] = $this->best([...$cards, ...$board]);
        $category = (int) array_search($name, self::HAND_NAMES, true);

        // Two pair and up is a hand worth chips; one pair is worth a call and not much more.
        return min(1.0, match ($category) {
            0 => 0.15,
            1 => 0.45,
            2 => 0.7,
            default => 0.8 + $category * 0.02,
        });
    }

    // --- helpers -------------------------------------------------------------------

    /** @return array<int, string> a 52-card deck, shuffled; dealt from the end */
    private function shuffled(): array
    {
        $deck = [];
        foreach (str_split(self::RANKS) as $rank) {
            foreach (str_split(self::SUITS) as $suit) {
                $deck[] = $rank.$suit;
            }
        }
        shuffle($deck);

        return $deck;
    }

    /** Move chips from a stack to the street's bet, capped at what they actually have. */
    private function putIn(Widget $widget, int $userId, int $amount): void
    {
        $state = $widget->state;
        $pid = (string) $userId;
        $amount = max(0, min($amount, (int) $state['players'][$pid]['chips']));

        $state['players'][$pid]['chips'] -= $amount;
        $state['players'][$pid]['bet'] = (int) $state['players'][$pid]['bet'] + $amount;
        $state['players'][$pid]['committed'] = (int) $state['players'][$pid]['committed'] + $amount;
        $state['players'][$pid]['acted'] = true;
        if ((int) $state['players'][$pid]['chips'] === 0) {
            $state['players'][$pid]['allIn'] = true;
        }
        $widget->state = $state;
    }

    /**
     * A raise puts the decision back to everyone else, however they've already acted.
     *
     * @param  array<string, mixed>  $players
     * @return array<string, mixed>
     */
    private function reopen(array $players, string $raiserId): array
    {
        foreach ($players as $pid => $player) {
            if ($pid !== $raiserId && empty($player['folded']) && empty($player['allIn'])) {
                $players[$pid]['acted'] = false;
            }
        }

        return $players;
    }

    /** @return array<string, mixed> the players still in the hand */
    private function livePlayers(array $state): array
    {
        return array_filter((array) $state['players'], fn ($p) => empty($p['folded']) && $p['cards'] !== []);
    }

    /** The next seat clockwise, wrapping. */
    private function nextSeat(array $seats, ?int $from): int
    {
        $index = $from === null ? -1 : array_search((int) $from, array_map('intval', $seats), true);

        return (int) $seats[($index === false ? 0 : $index + 1) % count($seats)];
    }

    /** The next player after `$from` who still has a decision to make. */
    private function nextActive(array $state, int $from): ?int
    {
        $seats = array_map('intval', array_values($state['seats']));
        $index = array_search($from, $seats, true);
        $start = $index === false ? 0 : $index;

        for ($i = 1; $i <= count($seats); $i++) {
            $id = $seats[($start + $i) % count($seats)];
            $player = $state['players'][(string) $id] ?? null;
            if ($player !== null && empty($player['folded']) && empty($player['allIn']) && $player['cards'] !== []) {
                return $id;
            }
        }

        return null;
    }

    /** Sit down with a fresh stack and no hand. */
    private function freshPlayer(string $name): array
    {
        return [
            'name' => $name, 'chips' => self::BUY_IN, 'bet' => 0, 'committed' => 0,
            'folded' => false, 'allIn' => false, 'acted' => false, 'cards' => [],
            'hand' => null, 'won' => 0, 'bot' => false,
        ];
    }

    /** Everything a new hand clears — the stack is the only thing that carries over. */
    private function freshHand(array $player): array
    {
        return [...$player, 'bet' => 0, 'committed' => 0, 'folded' => false, 'allIn' => false, 'acted' => false, 'cards' => [], 'hand' => null, 'won' => 0];
    }

    /** Empty the table without emptying the room: everyone keeps their seat, stacks reset. */
    private function reset(Widget $widget): WidgetOutcome
    {
        $state = $this->initialState();
        $state['seats'] = $widget->state['seats'] ?? [];
        $players = [];
        foreach ((array) ($widget->state['players'] ?? []) as $pid => $player) {
            $players[$pid] = [...$this->freshPlayer($player['name']), 'bot' => ! empty($player['bot'])];
        }
        $state['players'] = $players === [] ? (object) [] : $players;
        $state['log'] = ['♻️ New table — everyone back to '.self::BUY_IN.' chips.'];
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function pretty(string $card): string
    {
        return $card[0].match ($card[1]) {
            's' => '♠', 'h' => '♥', 'd' => '♦', default => '♣'
        };
    }

    /**
     * @param  array<int, string>  $log
     * @return array<int, string>
     */
    private function pushLog(array $log, string $entry): array
    {
        $log[] = $entry;

        return array_slice($log, -self::LOG_MAX);
    }

    private function help(): string
    {
        return implode("\n", [
            '🃏 **Side Poker — no-limit Texas Hold\'em**',
            '`h!poker` — open the table and take a seat',
            '`h!deal` — deal the next hand (needs two at the table; blinds '.self::SMALL_BLIND.'/'.self::BIG_BLIND.')',
            '`h!bot` — sit a house bot down, so you can play on your own',
            '`h!check` · `h!call` · `h!fold` · `h!raise <to>` · `h!allin` — or just use the buttons on the card',
            '`h!leave` — stand up (mid-hand, that folds you)',
            '`h!reset` — fresh table, everyone back to '.self::BUY_IN.' chips',
            'Your hole cards are only ever sent to you; the deck never leaves the server.',
        ]);
    }
}
