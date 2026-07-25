<?php

namespace App\Services\Games;

use App\Models\SpaceGame;
use App\Models\User;
use App\Support\SideSpace\Avatars;
use Illuminate\Validation\ValidationException;

/**
 * Two pets, one arena, taking turns — the duel the room's starters were always going to have.
 *
 * The second game on the framework, and the one that proves it was worth building as a framework:
 * it shares every piece of plumbing with the imposter game — proposing, the vote (here a single
 * accept), running moves, ending — and differs only in its four rules. Where Among Us is put to
 * the whole room, a battle is a *challenge* ({@see startMode}); the framework already knew how to
 * wait on one person's yes instead of a majority.
 *
 * ## The type triangle
 *
 * Grass beats water, water beats fire, fire beats grass — the oldest wheel in the genre, and the
 * only thing a player has to read on the board to know whether to press attack or guard. A pet's
 * element comes from which starter its owner chose ({@see Avatars}); somebody who never picked one
 * is handed a random starter for the fight rather than turned away.
 *
 * ## Nothing is secret
 *
 * Unlike Among Us, a battle has no hidden state — both duellists see the same HP, the same turn,
 * the same log — so {@see view} redacts nothing and only tells each viewer which side they're on.
 * The rules still live on the server, because HP a client could edit is a battle a client could
 * win by editing.
 */
class PetBattleGame implements GameHandler
{
    private const MAX_HP = 100;

    /** grass → water → fire → grass. What each element is strong against. */
    private const BEATS = ['grass' => 'water', 'water' => 'fire', 'fire' => 'grass'];

    public function type(): string
    {
        return 'petbattle';
    }

    public function label(): string
    {
        return 'Pet Battle';
    }

    public function blurb(): string
    {
        return 'Challenge someone to a turn-based battle between your companions.';
    }

    public function startMode(): string
    {
        return 'challenge';
    }

    public function minPlayers(): int
    {
        return 2;
    }

    public function maxPlayers(): int
    {
        return 2;
    }

    /**
     * Set the board: two pets, full HP, and a coin-toss for who moves first.
     *
     * @param  array<int, int>  $playerIds
     * @return array<string, mixed>
     */
    public function start(SpaceGame $game, array $playerIds): array
    {
        $users = User::query()->whereIn('id', $playerIds)->get()->keyBy('id');

        $pets = Avatars::pets();
        $players = [];
        foreach ($playerIds as $id) {
            $pet = $users[$id]?->space_pet;
            // No companion chosen? Handed a random starter for the bout rather than barred from it.
            if ($pet === null || ! isset($pets[$pet])) {
                $keys = Avatars::petKeys();
                $pet = $keys[array_rand($keys)];
            }

            $players[$id] = [
                'pet' => $pet,
                'element' => $pets[$pet]['element'],
                'name' => $pets[$pet]['label'],
            ];
        }

        $order = array_values($playerIds);
        $first = $order[array_rand($order)];

        return [
            'order' => $order,
            'players' => $players,
            'hp' => array_fill_keys($order, self::MAX_HP),
            'guard' => array_fill_keys($order, false),
            'turn' => $first,
            'round' => 1,
            'log' => ['The battle begins!'],
            'winner' => null,
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

        if (! isset($state['players'][$id])) {
            throw ValidationException::withMessages(['action' => 'You are not in this battle.']);
        }

        return match ($action) {
            'move' => $this->move($state, $id, (string) ($payload['move'] ?? '')),
            'forfeit' => $this->forfeit($state, $id),
            default => throw ValidationException::withMessages(['action' => 'Unknown move.']),
        };
    }

    /**
     * A battle is played in the open, so a viewer sees all of it — plus which fighter they are, so
     * the client knows whose move buttons to show.
     *
     * @return array<string, mixed>
     */
    public function view(SpaceGame $game, ?User $viewer): array
    {
        $state = $game->state;
        $me = $viewer?->id;

        // The two fighters, in a stable order, everything about them on show.
        $players = array_map(fn (int $id) => [
            'id' => $id,
            'name' => $state['players'][$id]['name'],
            'pet' => $state['players'][$id]['pet'],
            'element' => $state['players'][$id]['element'],
            'hp' => $state['hp'][$id],
            'max_hp' => self::MAX_HP,
            'guarding' => $state['guard'][$id] ?? false,
        ], $state['order'] ?? []);

        return [
            'players' => $players,
            'turn' => $state['turn'] ?? null,
            'round' => $state['round'] ?? 1,
            'you' => $me !== null && isset($state['players'][$me]) ? $me : null,
            'log' => array_slice($state['log'] ?? [], -6),
            'winner' => $state['winner'] ?? null,
        ];
    }

    // --- the moves ---

    /**
     * The four things a fighter can do on its turn.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function move(array $state, int $id, string $move): array
    {
        if (($state['turn'] ?? null) !== $id) {
            throw ValidationException::withMessages(['action' => 'It is not your turn.']);
        }

        $foe = $this->foe($state, $id);
        $me = $state['players'][$id];
        $log = "{$me['name']} ";

        if ($move === 'guard') {
            // Dig in: recover a little, and blunt the next hit.
            $state['hp'][$id] = min(self::MAX_HP, $state['hp'][$id] + 12);
            $state['guard'][$id] = true;
            $log .= 'guards, and catches its breath.';
        } else {
            $damage = $this->damage($state, $id, $foe, $move, $log);
            $state['hp'][$foe] = max(0, $state['hp'][$foe] - $damage);
        }

        $state['log'][] = $log;
        // Your turn passes whatever you did; your own guard is spent when it's your turn again,
        // so it only ever protects the one hit between now and then.
        $state['guard'][$id] = $move === 'guard';
        $state['turn'] = $foe;
        $state['round'] = ($state['round'] ?? 1) + 1;

        return $this->settle($state);
    }

    /**
     * Work out a hit, and describe it into `$log` by reference.
     *
     * @param  array<string, mixed>  $state
     */
    private function damage(array &$state, int $id, int $foe, string $move, string &$log): int
    {
        $mine = $state['players'][$id]['element'];
        $theirs = $state['players'][$foe]['element'];

        // tackle is plain; the elemental moves take the type triangle.
        [$base, $typed, $accuracy] = match ($move) {
            'tackle' => [16, false, 100],
            'strike' => [20, true, 100],
            'special' => [34, true, 72],
            default => throw ValidationException::withMessages(['move' => 'No such move.']),
        };

        if ($accuracy < 100 && random_int(1, 100) > $accuracy) {
            $log .= "uses {$move}, but misses!";

            return 0;
        }

        $effect = 1.0;
        if ($typed) {
            if ((self::BEATS[$mine] ?? null) === $theirs) {
                $effect = 1.5;
            } elseif ((self::BEATS[$theirs] ?? null) === $mine) {
                $effect = 0.66;
            }
        }

        // A little swing, so the same move isn't the same number twice.
        $variance = random_int(85, 100) / 100;
        $damage = (int) round($base * $effect * $variance);

        if (($state['guard'][$foe] ?? false)) {
            $damage = (int) round($damage / 2);
            $state['guard'][$foe] = false;
            $log .= "uses {$move}, but it's guarded — {$damage} damage.";
        } else {
            $note = $effect > 1 ? " It's super effective!" : ($effect < 1 ? ' Not very effective…' : '');
            $log .= "uses {$move} for {$damage} damage.{$note}";
        }

        return $damage;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function forfeit(array $state, int $id): array
    {
        $foe = $this->foe($state, $id);
        $state['winner'] = $foe;
        $state['log'][] = "{$state['players'][$id]['name']} forfeits.";

        return $state;
    }

    /**
     * Call the battle if anyone's down.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function settle(array $state): array
    {
        foreach ($state['order'] as $id) {
            if ($state['hp'][$id] <= 0) {
                $winner = $this->foe($state, $id);
                $state['winner'] = $winner;
                $state['log'][] = "{$state['players'][$id]['name']} is out. {$state['players'][$winner]['name']} wins!";
                break;
            }
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function foe(array $state, int $id): int
    {
        foreach ($state['order'] as $other) {
            if ($other !== $id) {
                return $other;
            }
        }

        return $id;
    }
}
