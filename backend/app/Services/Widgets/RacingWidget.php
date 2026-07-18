<?php

namespace App\Services\Widgets;

use App\Models\User;
use App\Models\Widget;
use App\Support\Commands\ParsedCommand;

/**
 * "Side Grand Prix" — the shared, persisted half of a co-op top-down racer, the racing
 * sibling of {@see ShooterWidget}. The *driving* runs client-side on a canvas
 * (see the CoopRacer card + lib/raceEngine); this owns only what must survive a refresh
 * and stay identical for everyone on the grid:
 *
 *   - `seed` — the one number every client feeds its deterministic track generator, so the
 *     circuit (its shape, width, checkpoints) is the same corners in the same places on
 *     every screen without anyone streaming track geometry. (Live *rival* cars ride
 *     whispers, not this — same trick as the shooter's teammate ghosts.)
 *   - `laps` — how many laps the race is; fixed for the whole grid so a "finish" means the
 *     same distance for everyone.
 *   - per-player `bestLap` / `lapsDone` — the pooled leaderboard. Lap times are reported one
 *     at a time as a driver crosses the line; only the *best* is kept, and it can only fall.
 *   - `finishers` + per-player `place` — finishing order is server-assigned in the order the
 *     "finish" actions arrive, so first past the flag on wave N is P1 for everyone, honestly,
 *     without trusting any one client's clock.
 *
 * State shape:
 *   status:    'idle' | 'racing' | 'finished'
 *   seed:      int
 *   laps:      int
 *   finishers: int         — how many have crossed the final flag (the next place to hand out)
 *   players:   { "<userId>": { name, bestLap, lapsDone, finished, finishMs, place } }
 *   log:       [ string, … ]   — last few events, newest last
 */
final class RacingWidget implements WidgetHandler
{
    private const TOTAL_LAPS = 3;

    private const LOG_MAX = 6;

    /** A lap under this many ms is impossible on this track — a tampered client, so ignored. */
    private const MIN_LAP_MS = 3_000;

    private const MAX_LAP_MS = 600_000;

    public function type(): string
    {
        return 'racing';
    }

    public function initialState(): array
    {
        return [
            'status' => 'idle',
            'seed' => 0,
            'laps' => self::TOTAL_LAPS,
            'finishers' => 0,
            'players' => (object) [],
            'log' => [],
        ];
    }

    public function command(Widget $widget, User $user, ParsedCommand $command): WidgetOutcome
    {
        return match ($command->verb) {
            'race', 'play', 'start', 'go', 'gp' => $this->green($widget),
            'reset', 'again', 'rematch', 'restart' => $this->reset($widget),
            'help', 'h' => WidgetOutcome::reply($this->help()),
            default => WidgetOutcome::reply("Unknown race command `r!{$command->verb}`. Try `r!help`."),
        };
    }

    public function action(Widget $widget, User $user, string $action, array $payload): WidgetOutcome
    {
        return match ($action) {
            'join' => $this->join($widget, $user),
            'lap' => $this->lap($widget, $user, $payload),
            'finish' => $this->finish($widget, $user, $payload),
            'reset' => $this->reset($widget),
            default => WidgetOutcome::noop(),
        };
    }

    /** `r!race`: drop the green flag on a fresh race, or bring the running one back down. */
    private function green(Widget $widget): WidgetOutcome
    {
        if (($widget->state['status'] ?? 'idle') === 'racing') {
            return WidgetOutcome::show();
        }

        return $this->reset($widget);
    }

    /** Line up a new grid: a new track seed, a clean board, everyone back to zero laps. */
    private function reset(Widget $widget): WidgetOutcome
    {
        $widget->state = [
            'status' => 'racing',
            'seed' => random_int(1, 1_000_000),
            'laps' => self::TOTAL_LAPS,
            'finishers' => 0,
            'players' => (object) [],
            'log' => ['🏁 Lights out — '.self::TOTAL_LAPS.' laps, take the grid!'],
        ];

        return WidgetOutcome::card();
    }

    /** Enrol a driver on the grid the moment they take the wheel. */
    private function join(Widget $widget, User $user): WidgetOutcome
    {
        $state = $widget->state;
        if (($state['status'] ?? 'idle') !== 'racing') {
            return $this->reset($widget);
        }

        $pid = (string) $user->id;
        $players = (array) $state['players'];
        if (isset($players[$pid])) {
            return WidgetOutcome::noop();
        }

        $players[$pid] = $this->freshDriver($user->name);
        $state['players'] = $players;
        $state['log'] = $this->pushLog($state['log'] ?? [], "🏎️ {$user->name} rolled onto the grid");
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    /**
     * A driver crossed the line: bank the lap and keep only their best. Times are clamped to
     * a plausible window so a tampered client can't post a 0.001s hot lap to the board.
     */
    private function lap(Widget $widget, User $user, array $payload): WidgetOutcome
    {
        $state = $widget->state;
        if (($state['status'] ?? 'idle') !== 'racing') {
            return WidgetOutcome::noop();
        }

        $ms = (int) ($payload['ms'] ?? 0);
        if ($ms < self::MIN_LAP_MS || $ms > self::MAX_LAP_MS) {
            return WidgetOutcome::noop();
        }

        $pid = (string) $user->id;
        $players = (array) $state['players'];
        $players[$pid] ??= $this->freshDriver($user->name);
        $players[$pid]['name'] = $user->name;
        $players[$pid]['lapsDone'] = (int) $players[$pid]['lapsDone'] + 1;

        $best = $players[$pid]['bestLap'];
        $players[$pid]['bestLap'] = $best === null ? $ms : min((int) $best, $ms);

        $state['players'] = $players;
        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    /**
     * A driver took the chequered flag. The first to report gets P1, the next P2, and so on —
     * finishing order is the order these actions land here, not any client's self-timing.
     * When the last driver on the grid is home, the race is `finished` for all.
     */
    private function finish(Widget $widget, User $user, array $payload): WidgetOutcome
    {
        $state = $widget->state;
        if (($state['status'] ?? 'idle') !== 'racing') {
            return WidgetOutcome::noop();
        }

        $pid = (string) $user->id;
        $players = (array) $state['players'];
        $players[$pid] ??= $this->freshDriver($user->name);
        if (! empty($players[$pid]['finished'])) {
            return WidgetOutcome::noop();
        }

        $place = (int) $state['finishers'] + 1;
        $state['finishers'] = $place;
        $players[$pid]['name'] = $user->name;
        $players[$pid]['finished'] = true;
        $players[$pid]['place'] = $place;
        $players[$pid]['lapsDone'] = max((int) $players[$pid]['lapsDone'], (int) $state['laps']);

        $finishMs = (int) ($payload['ms'] ?? 0);
        $players[$pid]['finishMs'] = ($finishMs > 0 && $finishMs <= self::MAX_LAP_MS * self::TOTAL_LAPS) ? $finishMs : null;

        $state['players'] = $players;
        $state['log'] = $this->pushLog($state['log'] ?? [], $this->ordinal($place)." — {$user->name} takes the flag! 🏁");

        // Everyone who lined up is home — wave the race over so the card shows the podium.
        $allHome = ! empty($players) && ! array_filter($players, fn ($p) => empty($p['finished']));
        if ($allHome) {
            $state['status'] = 'finished';
            $state['log'] = $this->pushLog($state['log'], '🏆 Race over — check the podium!');
        }

        $widget->state = $state;

        return WidgetOutcome::updated();
    }

    /** @return array{name: string, bestLap: null, lapsDone: int, finished: bool, finishMs: null, place: null} */
    private function freshDriver(string $name): array
    {
        return ['name' => $name, 'bestLap' => null, 'lapsDone' => 0, 'finished' => false, 'finishMs' => null, 'place' => null];
    }

    private function ordinal(int $n): string
    {
        return match ($n) {
            1 => '🥇 P1',
            2 => '🥈 P2',
            3 => '🥉 P3',
            default => "P{$n}",
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
            '🏎️ **Side Grand Prix — co-op top-down racer**',
            '`r!race` — line up a fresh race and drop the grid card',
            'Hit **Take the wheel** on the card, then WASD / arrows to drive — accelerate, brake, steer',
            'Everyone races the same '.self::TOTAL_LAPS.'-lap circuit; rivals show as live ghost cars',
            'Chase the fastest lap and be first to the flag',
            '`r!reset` — new track, fresh grid',
        ]);
    }
}
