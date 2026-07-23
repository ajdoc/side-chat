<?php

namespace App\Services\Widgets;

use App\Models\User;
use App\Models\Widget;
use App\Support\Commands\ParsedCommand;

/**
 * "Side Skribbl" — draw-and-guess, the channel's turn-taking word game.
 *
 * The drawing itself never comes here: strokes ride whispers straight between clients
 * (~peer-to-peer over Reverb, the same trick as the racer's ghost cars), because a canvas
 * stream is far too chatty for the database and worthless once the turn ends. What this
 * owns is everything that must be *the same for everyone* and everything a client must not
 * be trusted with:
 *
 *   - the **word**, which only the drawer may see. Widget state is normally handed to every
 *     viewer verbatim, so this handler implements {@see RedactsState} and strips the word
 *     for everyone else while a turn is live — guessers get `mask` ("_ _ _ _") and nothing
 *     more. Hiding it in the card would not be hiding it.
 *   - **judging guesses**. A guesser's client can't check its own guess (it doesn't know the
 *     answer), so every guess lands here and the server rules on it. That also means the
 *     server, not a client, decides the order people got it in.
 *   - **scores**, awarded on the clock the server keeps (`endsAt`), so a slow guesser can't
 *     claim a fast one's points.
 *   - **whose turn it is** — the rotation, and when the game is over.
 *
 * State shape:
 *   status:       'idle' | 'drawing' | 'reveal' | 'over'
 *   turn:         int          — 1-based turn counter; also the token that makes `next`/`timeup` idempotent
 *   turns:        int          — how many turns the whole game runs for
 *   drawerId:     int|null     — whose pen it is
 *   drawerName:   string|null
 *   word:         string|null  — THE SECRET (redacted for non-drawers while drawing)
 *   mask:         string|null  — the word as underscores, safe for everyone
 *   endsAt:       int          — epoch ms the turn expires
 *   revealEndsAt: int          — epoch ms the reveal card gives way to the next turn
 *   order:        [int, …]     — the drawing rotation, in join order
 *   correct:      [int, …]     — who's already got it this turn
 *   players:      { "<userId>": { name, score } }
 *   chat:         [ { name, text, ok, close } ]  — the guess feed, newest last
 *   log:          [ string, … ]
 *   used:         [ string, … ] — words already drawn this game, so they don't repeat
 */
final class SkribblWidget implements WidgetHandler, RedactsState
{
    /** How long a drawing turn runs. */
    private const TURN_MS = 80_000;

    /** How long the answer stays up between turns. */
    private const REVEAL_MS = 8_000;

    /** Turns per player — one full game is everyone drawing this many times. */
    private const ROUNDS = 2;

    private const CHAT_MAX = 12;

    private const LOG_MAX = 6;

    private const GUESS_MAX = 60;

    /** Points floor for a correct guess, however late it lands. */
    private const MIN_POINTS = 20;

    /** What the drawer earns each time someone gets their picture. */
    private const DRAWER_POINTS = 20;

    /** @var list<string> */
    private const WORDS = [
        'apple', 'rocket', 'penguin', 'guitar', 'volcano', 'toaster', 'octopus', 'ladder',
        'rainbow', 'pirate', 'sandwich', 'lighthouse', 'dragon', 'bicycle', 'cactus', 'wizard',
        'campfire', 'submarine', 'snowman', 'butterfly', 'skateboard', 'igloo', 'mermaid',
        'telescope', 'jellyfish', 'windmill', 'astronaut', 'pancake', 'hamburger', 'dinosaur',
        'umbrella', 'treasure', 'vampire', 'football', 'headphones', 'waterfall', 'scarecrow',
        'unicorn', 'lipstick', 'keyboard', 'balloon', 'anchor', 'castle', 'robot', 'giraffe',
        'popcorn', 'tornado', 'parachute', 'saxophone', 'hedgehog', 'coffee', 'spaghetti',
        'fireworks', 'kangaroo', 'wheelchair', 'microphone', 'sunflower', 'pyramid', 'koala',
        'necklace', 'chainsaw', 'flamingo', 'hourglass', 'compass', 'cupcake', 'squirrel',
        'trampoline', 'seahorse', 'moustache', 'bulldozer', 'chandelier', 'harmonica',
        'porcupine', 'snorkel', 'wristwatch', 'zebra', 'mailbox', 'accordion', 'blender',
        'cauldron', 'dumbbell', 'elevator', 'fountain', 'gargoyle', 'helicopter', 'iceberg',
        'jigsaw', 'kettle', 'lantern', 'magnet', 'ninja', 'origami', 'pineapple', 'quicksand',
        'raincoat', 'scissors', 'tractor', 'violin', 'walrus', 'yo-yo', 'zeppelin', 'anteater',
    ];

    public function type(): string
    {
        return 'skribbl';
    }

    public function initialState(): array
    {
        return [
            'status' => 'idle',
            'turn' => 0,
            'turns' => 0,
            'drawerId' => null,
            'drawerName' => null,
            'word' => null,
            'mask' => null,
            'endsAt' => 0,
            'revealEndsAt' => 0,
            'order' => [],
            'correct' => [],
            'players' => (object) [],
            'chat' => [],
            'log' => [],
            'used' => [],
        ];
    }

    /**
     * The state minus anything this viewer hasn't earned. Only the live word is secret, and
     * only while it's being drawn — once the turn is revealed or over, everyone sees it.
     */
    public function forViewer(Widget $widget, array $state, ?User $viewer): array
    {
        $isDrawer = $viewer !== null && (int) ($state['drawerId'] ?? 0) === $viewer->id;
        if (($state['status'] ?? '') === 'drawing' && ! $isDrawer) {
            $state['word'] = null;
        }

        // The used-words list ends with the word currently on the easel, so shipping it would
        // hand back exactly what the line above just took away. No card needs it either.
        unset($state['used']);

        return $state;
    }

    public function command(Widget $widget, User $user, ParsedCommand $command): WidgetOutcome
    {
        return match ($command->verb) {
            'play', 'start', 'draw', 'skribbl', 'game' => $this->start($widget, $user),
            'join' => $this->joinViaCommand($widget, $user),
            'reset', 'again', 'rematch', 'stop' => $this->reset($widget, card: true),
            'show', 'card' => WidgetOutcome::show(),
            'help', 'h' => WidgetOutcome::reply($this->help()),
            default => WidgetOutcome::reply("Unknown skribbl command `s!{$command->verb}`. Try `s!help`."),
        };
    }

    public function action(Widget $widget, User $user, string $action, array $payload): WidgetOutcome
    {
        return match ($action) {
            'join' => $this->join($widget, $user),
            'start' => $this->start($widget, $user),
            'guess' => $this->guess($widget, $user, (string) ($payload['text'] ?? '')),
            'timeup' => $this->timeup($widget, (int) ($payload['turn'] ?? 0)),
            'skip' => $this->skip($widget, $user, (int) ($payload['turn'] ?? 0)),
            'next' => $this->next($widget, (int) ($payload['turn'] ?? 0)),
            'reset' => $this->reset($widget),
            default => WidgetOutcome::noop(),
        };
    }

    // --- lobby -------------------------------------------------------------------------

    /** Sit down at the table. Joining mid-game is fine — you go to the back of the rotation. */
    private function join(Widget $widget, User $user): WidgetOutcome
    {
        $state = $widget->state;
        $pid = (string) $user->id;
        $players = (array) $state['players'];

        if (isset($players[$pid])) {
            // Already in, but keep the display name fresh.
            if (($players[$pid]['name'] ?? null) === $user->name) {
                return WidgetOutcome::noop();
            }
            $players[$pid]['name'] = $user->name;
            $state['players'] = $players;
            $widget->state = $state;

            return WidgetOutcome::updated();
        }

        $players[$pid] = ['name' => $user->name, 'score' => 0];
        $state['players'] = $players;
        $state['order'] = [...array_map('intval', $state['order'] ?? []), $user->id];
        $state['log'] = $this->pushLog($state['log'] ?? [], "✏️ {$user->name} joined the table");

        // A game already running gets longer, so the newcomer still gets their turns.
        if (in_array($state['status'], ['drawing', 'reveal'], true)) {
            $state['turns'] = count($state['order']) * self::ROUNDS;
        }

        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    private function joinViaCommand(Widget $widget, User $user): WidgetOutcome
    {
        $this->join($widget, $user);

        return WidgetOutcome::card();
    }

    /**
     * Deal the first turn. Anyone can call it; whoever's at the table plays, and the caller
     * is added if they weren't already.
     */
    private function start(Widget $widget, User $user): WidgetOutcome
    {
        $this->join($widget, $user);

        $state = $widget->state;
        if ($state['status'] === 'drawing') {
            return WidgetOutcome::show();
        }

        // A fresh game keeps who's at the table but wipes the scoreboard.
        $players = (array) $state['players'];
        foreach ($players as $id => $p) {
            $players[$id]['score'] = 0;
        }

        $order = array_values(array_map('intval', $state['order'] ?? []));
        $state = $this->initialState();
        $state['players'] = $players;
        $state['order'] = $order;
        $state['turns'] = max(1, count($order)) * self::ROUNDS;
        $state['log'] = ["🎨 Game on — {$state['turns']} turns, ".self::ROUNDS.' each. Pens ready!'];
        $widget->state = $state;

        return $this->beginTurn($widget, card: true);
    }

    /** Wipe the table back to an empty lobby. */
    private function reset(Widget $widget, bool $card = false): WidgetOutcome
    {
        $widget->state = $this->initialState();

        return $card ? WidgetOutcome::card() : WidgetOutcome::updated();
    }

    // --- turns -------------------------------------------------------------------------

    /** Hand the pen to whoever's next in the rotation and put a fresh word behind the mask. */
    private function beginTurn(Widget $widget, bool $card = false): WidgetOutcome
    {
        $state = $widget->state;
        $order = array_values(array_map('intval', $state['order'] ?? []));
        $players = (array) $state['players'];

        if ($order === []) {
            $state['status'] = 'idle';
            $widget->state = $state;

            return WidgetOutcome::updated();
        }

        $turn = (int) $state['turn'] + 1;
        if ($turn > (int) $state['turns']) {
            return $this->finish($widget);
        }

        // Straight round-robin: turn 1 is the first to join, and it wraps.
        $drawerId = $order[($turn - 1) % count($order)];
        $word = $this->pickWord((array) ($state['used'] ?? []));

        $state['status'] = 'drawing';
        $state['turn'] = $turn;
        $state['drawerId'] = $drawerId;
        $state['drawerName'] = $players[(string) $drawerId]['name'] ?? 'Someone';
        $state['word'] = $word;
        $state['mask'] = $this->mask($word);
        $state['endsAt'] = $this->nowMs() + self::TURN_MS;
        $state['revealEndsAt'] = 0;
        $state['correct'] = [];
        $state['chat'] = [];
        $state['used'] = [...(array) ($state['used'] ?? []), $word];
        $state['log'] = $this->pushLog(
            $state['log'] ?? [],
            "🖌️ Turn {$turn}/{$state['turns']} — {$state['drawerName']} is drawing",
        );

        $widget->state = $state;

        return $card ? WidgetOutcome::card() : WidgetOutcome::updated();
    }

    /**
     * Judge a guess. Everything about this is server-side on purpose: the guesser's client
     * doesn't hold the word (see {@see forViewer}), so it couldn't rule on itself even if we
     * trusted it to, and the points depend on the clock this side keeps.
     */
    private function guess(Widget $widget, User $user, string $text): WidgetOutcome
    {
        $state = $widget->state;
        $text = trim(mb_substr($text, 0, self::GUESS_MAX));
        if ($state['status'] !== 'drawing' || $text === '') {
            return WidgetOutcome::noop();
        }

        $pid = (string) $user->id;
        $players = (array) $state['players'];
        // Watching and typing counts as joining — you're playing now.
        if (! isset($players[$pid])) {
            $this->join($widget, $user);
            $state = $widget->state;
            $players = (array) $state['players'];
        }

        $correct = array_map('intval', (array) $state['correct']);
        $isDrawer = (int) $state['drawerId'] === $user->id;

        // The drawer typing the word would just spoil it, and a repeat guess earns nothing.
        if ($isDrawer || in_array($user->id, $correct, true)) {
            return WidgetOutcome::noop();
        }

        $answer = $this->normalise((string) $state['word']);
        $attempt = $this->normalise($text);

        if ($attempt !== $answer) {
            // A near miss is worth saying out loud — but never echo the answer itself.
            $close = $attempt !== '' && levenshtein($attempt, $answer) <= 1;
            $state['chat'] = $this->pushChat($state['chat'] ?? [], $user->name, $text, ok: false, close: $close);
            $widget->state = $state;

            return WidgetOutcome::updated();
        }

        // Got it. Points fall with the clock and with how many beat you to it.
        $place = count($correct) + 1;
        $remaining = max(0, (int) $state['endsAt'] - $this->nowMs());
        $points = max(self::MIN_POINTS, (int) round(30 + 70 * ($remaining / self::TURN_MS)) - ($place - 1) * 10);

        $players[$pid]['score'] = (int) ($players[$pid]['score'] ?? 0) + $points;
        $drawerKey = (string) $state['drawerId'];
        if (isset($players[$drawerKey])) {
            $players[$drawerKey]['score'] = (int) $players[$drawerKey]['score'] + self::DRAWER_POINTS;
        }

        $correct[] = $user->id;
        $state['correct'] = $correct;
        $state['players'] = $players;
        // The guess text is deliberately dropped here — it *is* the word.
        $state['chat'] = $this->pushChat($state['chat'] ?? [], $user->name, "guessed it! +{$points}", ok: true, close: false);
        $widget->state = $state;

        // Everyone but the drawer has it — no reason to keep drawing.
        $guessers = max(0, count((array) $state['players']) - 1);
        if ($guessers > 0 && count($correct) >= $guessers) {
            return $this->reveal($widget, 'Everyone got it! 🎉');
        }

        return WidgetOutcome::updated();
    }

    /**
     * The clock ran out. Any client may report it — they all watch the same `endsAt` — so
     * this checks the deadline really has passed and pins the report to a turn, making the
     * race between however many clients fire it a single, idempotent transition.
     */
    private function timeup(Widget $widget, int $turn): WidgetOutcome
    {
        $state = $widget->state;
        if ($state['status'] !== 'drawing' || $turn !== (int) $state['turn'] || $this->nowMs() < (int) $state['endsAt']) {
            return WidgetOutcome::noop();
        }

        return $this->reveal($widget, "⏰ Time! Nobody{$this->gotItSuffix($state)}");
    }

    /** The drawer bailing out — their own turn only, and it ends the same way time does. */
    private function skip(Widget $widget, User $user, int $turn): WidgetOutcome
    {
        $state = $widget->state;
        if ($state['status'] !== 'drawing' || $turn !== (int) $state['turn'] || (int) $state['drawerId'] !== $user->id) {
            return WidgetOutcome::noop();
        }

        return $this->reveal($widget, "🙈 {$user->name} gave up on this one");
    }

    /** Show the answer to the table and start the between-turns countdown. */
    private function reveal(Widget $widget, string $line): WidgetOutcome
    {
        $state = $widget->state;
        $state['status'] = 'reveal';
        $state['revealEndsAt'] = $this->nowMs() + self::REVEAL_MS;
        $state['log'] = $this->pushLog($state['log'] ?? [], $line." — the word was **{$state['word']}**");
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    /**
     * Move on from the reveal. Like `timeup` this is client-nudged and turn-pinned, so the
     * whole table firing it at once advances exactly one turn.
     */
    private function next(Widget $widget, int $turn): WidgetOutcome
    {
        $state = $widget->state;
        if ($state['status'] !== 'reveal' || $turn !== (int) $state['turn']) {
            return WidgetOutcome::noop();
        }

        return $this->beginTurn($widget);
    }

    /** Last turn's done: freeze the scoreboard and crown whoever's on top. */
    private function finish(Widget $widget): WidgetOutcome
    {
        $state = $widget->state;
        $state['status'] = 'over';
        $state['drawerId'] = null;
        $state['drawerName'] = null;
        $state['word'] = null;
        $state['mask'] = null;
        $state['endsAt'] = 0;
        $state['revealEndsAt'] = 0;
        $state['correct'] = [];

        $players = (array) $state['players'];
        uasort($players, fn ($a, $b) => (int) $b['score'] <=> (int) $a['score']);
        $winner = reset($players);
        $state['log'] = $this->pushLog(
            $state['log'] ?? [],
            $winner ? "🏆 {$winner['name']} wins with {$winner['score']} points!" : '🏁 Game over',
        );

        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    // --- helpers -----------------------------------------------------------------------

    /** @param array<int, string> $used */
    private function pickWord(array $used): string
    {
        $pool = array_values(array_diff(self::WORDS, $used));
        if ($pool === []) {
            $pool = self::WORDS;
        }

        return $pool[random_int(0, count($pool) - 1)];
    }

    /** The word as the table sees it: one underscore per letter, spacing and hyphens kept. */
    private function mask(string $word): string
    {
        return preg_replace('/[^\s-]/u', '_', $word);
    }

    /** Guesses are judged on letters and digits alone — case, spaces and punctuation don't count. */
    private function normalise(string $text): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($text)));
    }

    private function gotItSuffix(array $state): string
    {
        return count((array) $state['correct']) > 0 ? ' else got it' : ' got it';
    }

    /**
     * @param  array<int, array<string, mixed>>  $chat
     * @return array<int, array<string, mixed>>
     */
    private function pushChat(array $chat, string $name, string $text, bool $ok, bool $close): array
    {
        $chat[] = ['name' => $name, 'text' => $text, 'ok' => $ok, 'close' => $close];

        return array_slice($chat, -self::CHAT_MAX);
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

    private function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    private function help(): string
    {
        return implode("\n", [
            '🎨 **Side Skribbl — draw & guess**',
            '`s!play` — deal a new game and drop the card',
            'Hit **Join** on the card; one player draws, everyone else types guesses',
            'Only the drawer sees the word — the rest get `_ _ _ _` and the picture, live',
            'Guess fast: points fall with the clock, and the drawer scores on every correct guess',
            '`s!reset` — clear the table',
        ]);
    }
}
