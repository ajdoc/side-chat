<?php

namespace App\Services\Games;

use App\Models\SideSpaceMap;
use App\Models\SpaceGame;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The imposter game, played in the room you were already walking around.
 *
 * A crew of players share a map and a list of tasks; hidden among them are one or two impostors,
 * whose job is to kill the crew off before the tasks are finished. It leans hard on what the room
 * already is: you walk to your tasks and press E to do them (the same E that uses furniture), the
 * proximity voice *is* how you accuse each other, and a meeting is simply the moment that voice
 * opens up to the whole room at once.
 *
 * ## What's secret, and what the server keeps
 *
 * Exactly one thing has to be hidden and hidden *properly*: who the impostors are. That lives in
 * `state` and is stripped per-viewer in {@see view} — a player learns their own role and no one
 * else's until the game reveals it. Everything a client could otherwise lie about — that a task
 * is done, that a kill is off cooldown, that a vote was cast — is checked here, on the server,
 * against the authoritative state. The one thing the server can't police is *positions* (those
 * are whispered peer-to-peer for the room's movement, and never reach it), so kill range is
 * trusted to the client; among friends playing a party game, that's the right trade, and it's the
 * only one made.
 *
 * ## Timers without a scheduler
 *
 * Meetings and kill cooldowns end at a stored wall-clock deadline (`ends_at`, `cooldowns`) rather
 * than by a server tick, because there is no per-game tick to lean on. Whoever's client notices
 * the deadline has passed asks the server to act on it, and the server re-checks the deadline
 * against its own clock before it does — so the deadline is authoritative even though nothing on
 * the server is counting down to it.
 */
class AmongUsGame implements GameHandler
{
    private const KILL_COOLDOWN_MS = 20_000;

    private const MEETING_MS = 45_000;

    private const TASKS_PER_CREW = 3;

    /** From this many players up, two impostors rather than one. */
    private const TWO_IMPOSTOR_AT = 7;

    public function type(): string
    {
        return 'amongus';
    }

    public function label(): string
    {
        return 'Impostors';
    }

    public function startMode(): string
    {
        return 'vote';
    }

    public function blurb(): string
    {
        return 'Do your tasks, find the impostors, and vote them out — before they get everyone.';
    }

    public function minPlayers(): int
    {
        return 3;
    }

    public function maxPlayers(): int
    {
        return 15;
    }

    /**
     * Deal roles, scatter tasks, and start the clock.
     *
     * @param  array<int, int>  $playerIds
     * @return array<string, mixed>
     */
    public function start(SpaceGame $game, array $playerIds): array
    {
        $shuffled = $playerIds;
        shuffle($shuffled);

        $impostorCount = count($shuffled) >= self::TWO_IMPOSTOR_AT ? 2 : 1;
        $impostors = array_slice($shuffled, 0, $impostorCount);

        $players = [];
        foreach ($playerIds as $id) {
            $players[$id] = [
                'role' => in_array($id, $impostors, true) ? 'impostor' : 'crew',
                'alive' => true,
            ];
        }

        // Tasks are real walkable tiles on the room's own map, so doing one means walking there.
        $spots = $this->taskSpots($game->channel->spaceMap);

        $tasks = [];
        $taskGoal = 0;
        foreach ($players as $id => $player) {
            $tasks[$id] = $this->dealTasks($spots);

            // Only crew tasks count towards the win. Impostors get a list too — it's their cover,
            // and a room where the impostor visibly never does tasks is a room with no game in it.
            if ($player['role'] === 'crew') {
                $taskGoal += count($tasks[$id]);
            }
        }

        $now = $this->now();
        $cooldowns = [];
        foreach ($impostors as $id) {
            // No opening-second kill — everyone gets to disperse first.
            $cooldowns[$id] = $now + self::KILL_COOLDOWN_MS;
        }

        return [
            'phase' => 'play',
            'players' => $players,
            'tasks' => $tasks,
            'bodies' => [],
            'cooldowns' => $cooldowns,
            'meeting' => null,
            'revealed' => [],
            'log' => ['The game has begun. Impostors, lie low.'],
            'task_goal' => $taskGoal,
            'task_done' => 0,
            'winner' => null,
            'started_at' => $now,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function act(SpaceGame $game, User $actor, string $action, array $payload): array
    {
        $state = $game->state;
        $id = $actor->id;

        return match ($action) {
            'complete_task' => $this->completeTask($state, $id, (string) ($payload['task'] ?? '')),
            'kill' => $this->kill($state, $id, (int) ($payload['target'] ?? 0), $payload),
            'call_meeting' => $this->callMeeting($state, $id),
            'vote' => $this->castVote($state, $id, $payload['target'] ?? 'skip'),
            'resolve_meeting' => $this->resolveMeeting($state),
            default => throw ValidationException::withMessages(['action' => 'Unknown move.']),
        };
    }

    /**
     * The state as `$viewer` may see it. The whole secrecy of the game is this method.
     *
     * @return array<string, mixed>
     */
    public function view(SpaceGame $game, ?User $viewer): array
    {
        $state = $game->state;
        $me = $viewer?->id;
        $over = ($state['winner'] ?? null) !== null;
        $revealed = $state['revealed'] ?? [];
        $iAmImpostor = $me !== null && ($state['players'][$me]['role'] ?? null) === 'impostor';

        // Roles: your own always; anyone else's only once revealed (ejected) or once it's over —
        // except that impostors know each other, which is the one alliance the game turns on.
        $players = [];
        foreach ($state['players'] ?? [] as $pid => $player) {
            $show = $over
                || $pid == $me
                || in_array((int) $pid, $revealed, true)
                || ($iAmImpostor && $player['role'] === 'impostor');

            $players[$pid] = [
                'alive' => $player['alive'],
                'role' => $show ? $player['role'] : null,
            ];
        }

        // Tasks: only your own list. The rest of the room sees only the shared progress bar.
        $myTasks = $me !== null ? ($state['tasks'][$me] ?? []) : [];

        $meeting = $state['meeting'] ?? null;
        if ($meeting !== null) {
            $meeting = [
                'by' => $meeting['by'],
                'reason' => $meeting['reason'],
                'ends_at' => $meeting['ends_at'],
                // Who has voted, but not for whom — a public ballot would gut the discussion.
                'voted' => array_map('intval', array_keys($meeting['votes'])),
                'mine' => $me !== null ? ($meeting['votes'][$me] ?? null) : null,
            ];
        }

        return [
            'phase' => $state['phase'],
            'players' => $players,
            'my_role' => $me !== null ? ($state['players'][$me]['role'] ?? null) : null,
            'my_tasks' => $myTasks,
            'bodies' => $state['bodies'] ?? [],
            'my_cooldown' => $me !== null ? ($state['cooldowns'][$me] ?? null) : null,
            'meeting' => $meeting,
            'log' => array_slice($state['log'] ?? [], -6),
            'task_goal' => $state['task_goal'] ?? 0,
            'task_done' => $state['task_done'] ?? 0,
            'winner' => $state['winner'] ?? null,
        ];
    }

    // --- the moves ---

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function completeTask(array $state, int $id, string $taskId): array
    {
        $this->requireAlive($state, $id);

        $tasks = $state['tasks'][$id] ?? [];
        $found = false;

        foreach ($tasks as $i => $task) {
            if ($task['id'] === $taskId && ! $task['done']) {
                $tasks[$i]['done'] = true;
                $found = true;
                break;
            }
        }

        if (! $found) {
            throw ValidationException::withMessages(['task' => 'No such task, or it is already done.']);
        }

        $state['tasks'][$id] = $tasks;

        // Only a crewmate's task moves the bar — an impostor's identical-looking task is cover.
        if (($state['players'][$id]['role'] ?? null) === 'crew') {
            $state['task_done'] = ($state['task_done'] ?? 0) + 1;
        }

        return $this->settle($state);
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $payload @return array<string, mixed> */
    private function kill(array $state, int $id, int $target, array $payload): array
    {
        $this->requireAlive($state, $id);

        if (($state['players'][$id]['role'] ?? null) !== 'impostor') {
            throw ValidationException::withMessages(['action' => 'Only an impostor can do that.']);
        }

        if (($state['phase'] ?? 'play') !== 'play') {
            throw ValidationException::withMessages(['action' => 'Not during a meeting.']);
        }

        if (($state['cooldowns'][$id] ?? 0) > $this->now()) {
            throw ValidationException::withMessages(['action' => 'Your kill is still on cooldown.']);
        }

        $victim = $state['players'][$target] ?? null;
        if ($victim === null || ! $victim['alive'] || ($victim['role'] ?? null) === 'impostor') {
            throw ValidationException::withMessages(['target' => 'You can only get a living crewmate.']);
        }

        $state['players'][$target]['alive'] = false;
        $state['bodies'][] = [
            'user' => $target,
            'x' => (int) ($payload['x'] ?? 0),
            'y' => (int) ($payload['y'] ?? 0),
        ];
        $state['cooldowns'][$id] = $this->now() + self::KILL_COOLDOWN_MS;

        return $this->settle($state);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function callMeeting(array $state, int $id): array
    {
        $this->requireAlive($state, $id);

        if (($state['meeting'] ?? null) !== null) {
            throw ValidationException::withMessages(['action' => 'A meeting is already under way.']);
        }

        $reason = ($state['bodies'] ?? []) !== [] ? 'body' : 'emergency';

        // Everyone's whisked back to the entrance and the bodies are cleared, exactly as a
        // meeting does — the teleport itself is the client's job, the truth of it is here.
        $state['phase'] = 'meeting';
        $state['bodies'] = [];
        $state['meeting'] = [
            'by' => $id,
            'reason' => $reason,
            'ends_at' => $this->now() + self::MEETING_MS,
            'votes' => [],
        ];
        $state['log'][] = $reason === 'body' ? 'A body was reported.' : 'An emergency meeting was called.';

        return $state;
    }

    /** @param array<string, mixed> $state @param int|string $target @return array<string, mixed> */
    private function castVote(array $state, int $id, int|string $target): array
    {
        $this->requireAlive($state, $id);

        $meeting = $state['meeting'] ?? null;
        if ($meeting === null) {
            throw ValidationException::withMessages(['action' => 'There is no meeting.']);
        }

        // 'skip', or the id of a living player. A vote for a ghost is a wasted one, so refuse it.
        if ($target !== 'skip') {
            $target = (int) $target;
            if (! ($state['players'][$target]['alive'] ?? false)) {
                throw ValidationException::withMessages(['target' => 'You can only vote for someone still in the game.']);
            }
        }

        $meeting['votes'][$id] = $target;
        $state['meeting'] = $meeting;

        // Everyone still alive has had their say — no reason to wait out the clock.
        if (count($meeting['votes']) >= $this->aliveCount($state)) {
            return $this->resolveMeeting($state);
        }

        return $state;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function resolveMeeting(array $state): array
    {
        $meeting = $state['meeting'] ?? null;
        if ($meeting === null) {
            throw ValidationException::withMessages(['action' => 'There is no meeting to resolve.']);
        }

        // Called on the clock running out *or* the last vote landing. If it's the clock, hold the
        // caller to it — a client can't end a meeting early by claiming the timer's up.
        $everyoneVoted = count($meeting['votes']) >= $this->aliveCount($state);
        if (! $everyoneVoted && $this->now() < $meeting['ends_at']) {
            throw ValidationException::withMessages(['action' => 'The meeting is still going.']);
        }

        // Tally. Skips and ties eject nobody — the crew has to actually agree.
        $tally = [];
        foreach ($meeting['votes'] as $choice) {
            $key = is_int($choice) ? $choice : 'skip';
            $tally[$key] = ($tally[$key] ?? 0) + 1;
        }

        arsort($tally);
        $top = array_key_first($tally) ?? 'skip';
        $topVotes = $tally[$top] ?? 0;
        $tied = count(array_filter($tally, static fn ($n) => $n === $topVotes)) > 1;

        if ($top !== 'skip' && $top !== null && ! $tied) {
            $ejected = (int) $top;
            $state['players'][$ejected]['alive'] = false;
            $state['revealed'][] = $ejected;
            $role = $state['players'][$ejected]['role'];
            $state['log'][] = "Player {$ejected} was ejected. They were "
                .($role === 'impostor' ? 'an Impostor.' : 'not an Impostor.');
        } else {
            $state['log'][] = 'Nobody was ejected.';
        }

        // Back to play: meeting closed, cooldowns reset so no post-meeting instant kill.
        $state['meeting'] = null;
        $state['phase'] = 'play';
        $now = $this->now();
        foreach ($state['players'] as $pid => $player) {
            if ($player['role'] === 'impostor' && $player['alive']) {
                $state['cooldowns'][$pid] = $now + self::KILL_COOLDOWN_MS;
            }
        }

        return $this->settle($state);
    }

    // --- helpers ---

    /**
     * Decide the game after every consequential move.
     *
     * Impostors win the moment they're not outnumbered — one impostor and one crewmate left is a
     * kill the crew can't survive, so the game is called there rather than played out. Crew win by
     * finishing every task or ejecting every impostor.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function settle(array $state): array
    {
        $impostors = 0;
        $crew = 0;
        foreach ($state['players'] as $player) {
            if (! $player['alive']) {
                continue;
            }
            $player['role'] === 'impostor' ? $impostors++ : $crew++;
        }

        if ($impostors === 0) {
            $state['winner'] = 'crew';
            $state['log'][] = 'The crew got every impostor. Crew win!';
        } elseif ($impostors >= $crew) {
            $state['winner'] = 'impostor';
            $state['log'][] = 'The impostors have the numbers. Impostors win.';
        } elseif (($state['task_goal'] ?? 0) > 0 && ($state['task_done'] ?? 0) >= $state['task_goal']) {
            $state['winner'] = 'crew';
            $state['log'][] = 'Every task is done. Crew win!';
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function requireAlive(array $state, int $id): void
    {
        if (! ($state['players'][$id]['alive'] ?? false)) {
            throw ValidationException::withMessages(['action' => 'Only the living can do that.']);
        }
    }

    /** @param array<string, mixed> $state */
    private function aliveCount(array $state): int
    {
        return count(array_filter($state['players'], static fn ($p) => $p['alive']));
    }

    /**
     * Task locations: distinct walkable tiles spread across the room.
     *
     * @return array<int, array{x: int, y: int}>
     */
    private function taskSpots(?SideSpaceMap $map): array
    {
        if ($map === null) {
            return [];
        }

        $walkable = [];
        for ($y = 0; $y < $map->height; $y++) {
            for ($x = 0; $x < $map->width; $x++) {
                if ($map->isWalkable($x, $y)) {
                    $walkable[] = ['x' => $x, 'y' => $y];
                }
            }
        }

        shuffle($walkable);

        // A pool of a dozen or so; each crewmate is dealt a few from it. More than that and the
        // map is wall-to-wall task markers.
        return array_slice($walkable, 0, 16);
    }

    /**
     * A few tasks for one player, drawn from the shared pool of spots.
     *
     * @param  array<int, array{x: int, y: int}>  $spots
     * @return array<int, array{id: string, x: int, y: int, done: bool}>
     */
    private function dealTasks(array $spots): array
    {
        if ($spots === []) {
            return [];
        }

        $pool = $spots;
        shuffle($pool);
        $pool = array_slice($pool, 0, self::TASKS_PER_CREW);

        return array_map(fn (array $spot, int $i) => [
            'id' => 't'.$i.'-'.$spot['x'].'-'.$spot['y'],
            'x' => $spot['x'],
            'y' => $spot['y'],
            'done' => false,
        ], $pool, array_keys($pool));
    }

    private function now(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
