<?php

namespace App\Services\Widgets;

use App\Models\User;
use App\Models\Widget;
use App\Support\Commands\ParsedCommand;

/**
 * "Side Squadron" — the shared, persisted half of a co-op Galaga-style shooter. The *game*
 * runs client-side on a canvas (see the CoopShooter card + lib/squadronEngine); this owns
 * only the things that must survive a refresh and stay the same for everyone:
 *
 *   - `seed` — the one number every client feeds its deterministic spawner, so wave N of a
 *     run is the same formation in the same slots on every screen without anyone streaming
 *     enemy positions. (Live *teammate* positions ride whispers, not this.)
 *   - `wave` — the run's high-water mark; whoever clears a wave first drags the squad to the
 *     next one (`wave` action, taken as a max), so nobody fights an old wave alone for long.
 *   - `teamLives` — one shared pool. Your local death spends a life for everyone; at zero the
 *     run is `lost` for all, which is the co-op stake.
 *   - `score` + per-player `kills` — the pooled tally and the leaderboard. Kills are reported
 *     in *batches* (a client accumulates them and flushes every few seconds), because one
 *     action per invader would be one HTTP round trip and one broadcast per invader.
 *
 * State shape:
 *   status:    'idle' | 'active' | 'lost'
 *   wave:      int
 *   seed:      int
 *   score:     int
 *   teamLives: int         maxLives: int
 *   players:   { "<userId>": { name, kills } }
 *   log:       [ string, … ]   — last few events, newest last
 */
final class ShooterWidget implements WidgetHandler
{
    private const START_LIVES = 6;

    private const LOG_MAX = 6;

    public function type(): string
    {
        return 'shooter';
    }

    public function initialState(): array
    {
        return [
            'status' => 'idle',
            'wave' => 0,
            'seed' => 0,
            'score' => 0,
            'teamLives' => self::START_LIVES,
            'maxLives' => self::START_LIVES,
            'players' => (object) [],
            'log' => [],
        ];
    }

    public function command(Widget $widget, User $user, ParsedCommand $command): WidgetOutcome
    {
        return match ($command->verb) {
            'raid', 'play', 'start', 'g' => $this->summon($widget),
            'reset', 'again', 'retry', 'gg' => $this->reset($widget),
            'help', 'h' => WidgetOutcome::reply($this->help()),
            default => WidgetOutcome::reply("Unknown raid command `g!{$command->verb}`. Try `g!help`."),
        };
    }

    public function action(Widget $widget, User $user, string $action, array $payload): WidgetOutcome
    {
        return match ($action) {
            'join' => $this->join($widget, $user),
            'frag' => $this->frag($widget, $user, $payload),
            'wave' => $this->advanceWave($widget, (int) ($payload['wave'] ?? 0)),
            'died' => $this->died($widget, $user),
            'reset' => $this->reset($widget),
            default => WidgetOutcome::noop(),
        };
    }

    /** `g!raid`: begin a fresh raid, or just bring the running one back to the bottom. */
    private function summon(Widget $widget): WidgetOutcome
    {
        if (($widget->state['status'] ?? 'idle') === 'active') {
            return WidgetOutcome::show();
        }

        return $this->reset($widget);
    }

    /** Start over: wave 1, a new shared seed, a full life pool, an empty roster. */
    private function reset(Widget $widget): WidgetOutcome
    {
        $widget->state = [
            'status' => 'active',
            'wave' => 1,
            'seed' => random_int(1, 1_000_000),
            'score' => 0,
            'teamLives' => self::START_LIVES,
            'maxLives' => self::START_LIVES,
            'players' => (object) [],
            'log' => ['🚀 Squadron scrambled — clear the skies!'],
        ];

        return WidgetOutcome::card();
    }

    /** Enrol a player on the roster the moment they deploy into the arena. */
    private function join(Widget $widget, User $user): WidgetOutcome
    {
        $state = $widget->state;
        if (($state['status'] ?? 'idle') !== 'active') {
            return $this->reset($widget);
        }

        $pid = (string) $user->id;
        $players = (array) $state['players'];
        if (isset($players[$pid])) {
            return WidgetOutcome::noop();
        }

        $players[$pid] = ['name' => $user->name, 'kills' => 0];
        $state['players'] = $players;
        $state['log'] = $this->pushLog($state['log'] ?? [], "🎮 {$user->name} launched");
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    /**
     * A batched kill report: add this player's kills to their tally and the pooled score.
     * Clamped so a tampered client can't teleport the leaderboard.
     */
    private function frag(Widget $widget, User $user, array $payload): WidgetOutcome
    {
        $state = $widget->state;
        if (($state['status'] ?? 'idle') !== 'active') {
            return WidgetOutcome::noop();
        }

        $kills = max(0, min(100, (int) ($payload['kills'] ?? 0)));
        $points = max(0, min(100_000, (int) ($payload['points'] ?? 0)));
        if ($kills === 0 && $points === 0) {
            return WidgetOutcome::noop();
        }

        $pid = (string) $user->id;
        $players = (array) $state['players'];
        $players[$pid] ??= ['name' => $user->name, 'kills' => 0];
        $players[$pid]['name'] = $user->name;
        $players[$pid]['kills'] = (int) $players[$pid]['kills'] + $kills;
        $state['players'] = $players;
        $state['score'] = (int) $state['score'] + $points;
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    /** Someone cleared a wave — drag the whole team forward if they were the first. */
    private function advanceWave(Widget $widget, int $wave): WidgetOutcome
    {
        $state = $widget->state;
        if (($state['status'] ?? 'idle') !== 'active' || $wave <= (int) $state['wave']) {
            return WidgetOutcome::noop();
        }

        $state['wave'] = $wave;
        $state['log'] = $this->pushLog($state['log'] ?? [], "🌊 Wave {$wave} — hold formation!");
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    /** A local death spends one shared life; the last one ends the raid for everyone. */
    private function died(Widget $widget, User $user): WidgetOutcome
    {
        $state = $widget->state;
        if (($state['status'] ?? 'idle') !== 'active') {
            return WidgetOutcome::noop();
        }

        $state['teamLives'] = max(0, (int) $state['teamLives'] - 1);
        if ($state['teamLives'] <= 0) {
            $state['status'] = 'lost';
            $state['log'] = $this->pushLog($state['log'] ?? [], "💀 {$user->name} was shot down — the squadron is wiped on wave {$state['wave']}");
        } else {
            $state['log'] = $this->pushLog($state['log'] ?? [], "🩸 {$user->name} was shot down · {$state['teamLives']} lives left");
        }
        $widget->state = $state;

        return WidgetOutcome::updated();
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
            '🚀 **Side Squadron — co-op Galaga-style shooter**',
            '`g!raid` — scramble a squadron and drop the arena card',
            'Hit **Launch** on the card, then ← → (or A/D) to steer, space or click to fire',
            'You fly the same waves together and share a pool of lives — clear waves, climb the leaderboard',
            '`g!reset` — start a fresh run',
        ]);
    }
}
