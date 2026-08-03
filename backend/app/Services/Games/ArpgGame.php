<?php

namespace App\Services\Games;

use App\Models\ArpgCharacter;
use App\Models\SpaceGame;
use App\Models\User;
use App\Support\Arpg\Jobs;
use App\Support\Arpg\Skills;
use Illuminate\Validation\ValidationException;

/**
 * The Labyrinth — a Diablo-shaped dungeon crawl you can play alone or with the room.
 *
 * Every other game here is a *round*: it starts, it resolves, the row is overwritten by whatever
 * the room plays next. A crawl isn't. The run is disposable — a seed, a floor, some monsters —
 * but the hero who walked in keeps their levels, and that split is the whole architecture:
 *
 *   - the **run** lives in this game's `state`, like every other game;
 *   - the **hero** lives in {@see ArpgCharacter}, belonging to a user rather than a room, so a
 *     level 30 sorcerer survives the next person proposing charades.
 *
 * ## Where the game actually runs
 *
 * Not here. A crawl is real-time — a hundred decisions a second — and the game path through
 * `space/game/act` assumes moves are *rare*, because every one of them is a round trip and a
 * refetch for the whole room. So the floor is generated on each client from the shared `seed`
 * (identical dungeons everywhere, with nothing to transmit), monsters are simulated by one
 * client and whispered peer-to-peer, and this handler sees only the outcomes that have to
 * outlive the frame they happened in: experience earned, gold taken, an item picked up, a floor
 * descended, a hero killed.
 *
 * That means the client is trusted about what happened in the fight, exactly as Among Us trusts
 * it about kill range, and for the same reason: the server never sees positions, so it cannot
 * referee a swing. What it *can* do is refuse the absurd, so {@see PROGRESS_XP_CAP} and the
 * inventory limits below bound a broken or malicious client to cheating at a human pace rather
 * than awarding itself a million levels in one call. Among friends in a private room, that's the
 * right trade; a public ladder would need a real game server, and would deserve one.
 */
class ArpgGame implements GameHandler
{
    /**
     * The classes you can roll: what they start with, and which attribute their damage comes off.
     *
     * `primary` is the whole of what an attribute *does* for a class — a mage's staff swings on
     * magic where a thief's dagger swings on dexterity — and it's here rather than in the engine
     * because it's a fact about the class, not about the frame. What actually distinguishes one
     * class from another is its skill tree; see {@see Skills}.
     */
    public const CLASSES = [
        'swordsman' => ['primary' => 'strength', 'stats' => ['strength' => 30, 'dexterity' => 20, 'magic' => 10, 'vitality' => 25]],
        'crusader' => ['primary' => 'strength', 'stats' => ['strength' => 27, 'dexterity' => 15, 'magic' => 18, 'vitality' => 25]],
        'archer' => ['primary' => 'dexterity', 'stats' => ['strength' => 18, 'dexterity' => 32, 'magic' => 15, 'vitality' => 20]],
        'thief' => ['primary' => 'dexterity', 'stats' => ['strength' => 20, 'dexterity' => 33, 'magic' => 12, 'vitality' => 20]],
        'mage' => ['primary' => 'magic', 'stats' => ['strength' => 12, 'dexterity' => 18, 'magic' => 38, 'vitality' => 17]],
        'priest' => ['primary' => 'magic', 'stats' => ['strength' => 15, 'dexterity' => 16, 'magic' => 34, 'vitality' => 20]],
        'necromancer' => ['primary' => 'magic', 'stats' => ['strength' => 13, 'dexterity' => 18, 'magic' => 37, 'vitality' => 17]],
        'druid' => ['primary' => 'magic', 'stats' => ['strength' => 20, 'dexterity' => 17, 'magic' => 30, 'vitality' => 18]],
    ];

    /**
     * What somebody who has never rolled a hero gets handed at the portal.
     *
     * Named rather than inline because it's referenced from two places and, being a key into
     * {@see CLASSES}, is exactly the sort of string that quietly stops existing when the roster
     * is reworked.
     */
    public const DEFAULT_CLASS = 'swordsman';

    /** How deep it goes. Reaching the bottom and killing what's there wins the run. */
    public const MAX_DEPTH = 16;

    /** The most experience one `progress` call may carry — see the class note on trust. */
    private const PROGRESS_XP_CAP = 5_000;

    /** The most gold one `progress` call may carry. */
    private const PROGRESS_GOLD_CAP = 5_000;

    /** A hero can carry this much before they have to leave something on the floor. */
    private const INVENTORY_LIMIT = 40;

    public function type(): string
    {
        return 'arpg';
    }

    public function label(): string
    {
        return 'The Labyrinth';
    }

    public function blurb(): string
    {
        return 'Take your hero down into the dungeon — alone, or with whoever walks in after you.';
    }

    /** Nobody is asked. You open a portal and go; the room joins you or doesn't. */
    public function startMode(): string
    {
        return 'open';
    }

    public function joinable(): bool
    {
        return true;
    }

    public function minPlayers(): int
    {
        return 1;
    }

    /** Four in a party — beyond that the floor is a crowd and the whispers are a flood. */
    public function maxPlayers(): int
    {
        return 4;
    }

    /**
     * Open a portal: one seed, one floor, and whoever opened it.
     *
     * @param  array<int, int>  $playerIds
     * @return array<string, mixed>
     */
    public function start(SpaceGame $game, array $playerIds): array
    {
        $players = [];
        foreach ($playerIds as $id) {
            $user = User::find($id);
            if ($user !== null) {
                $players[$id] = $this->seat($user);
            }
        }

        // The seed *is* the dungeon. Every client generates the same floors from it, so a party
        // shares a world without a byte of it crossing the wire.
        return [
            'seed' => random_int(1, 2_000_000_000),
            'depth' => 1,
            'players' => $players,
            'log' => ['The portal opens. Something below is awake.'],
            'winner' => null,
        ];
    }

    /**
     * Somebody walks in behind you, on the floor the party is already on.
     *
     * @return array<string, mixed>
     */
    public function join(SpaceGame $game, User $user): array
    {
        $state = $game->state;
        $state['players'][$user->id] = $this->seat($user);
        $state['log'][] = $this->nameOf($state, $user->id).' steps through the portal.';

        return $this->trimLog($state);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function act(SpaceGame $game, User $actor, string $action, array $payload): array
    {
        $state = $game->state;
        $id = $actor->id;

        if (! array_key_exists($id, $state['players'] ?? [])) {
            throw ValidationException::withMessages(['action' => 'You are not in this run.']);
        }

        return match ($action) {
            'progress' => $this->progress($state, $id, $payload),
            'loot' => $this->loot($state, $id, $payload),
            'equip' => $this->equip($state, $id, $payload),
            'drop' => $this->drop($state, $id, $payload),
            'learn' => $this->learnInRun($state, $id, $payload),
            'advance' => $this->advanceInRun($state, $id),
            'died' => $this->died($state, $id),
            'revive' => $this->revive($state, $id),
            'descend' => $this->descend($state, $id),
            'leave' => $this->leave($state, $id),
            default => throw ValidationException::withMessages(['action' => 'Unknown move.']),
        };
    }

    /**
     * Your own hero in full; everyone else's as the party sees them.
     *
     * A crawl has no secret like the impostor's, but a bag is still nobody else's business — the
     * party needs to know you're a level 12 rogue on 40% health, not what you're hoarding.
     *
     * @return array<string, mixed>
     */
    public function view(SpaceGame $game, ?User $viewer): array
    {
        $state = $game->state;
        $me = $viewer?->id;

        $players = [];
        foreach ($state['players'] ?? [] as $pid => $player) {
            $players[$pid] = [
                'name' => $player['name'],
                'class' => $player['class'],
                // The party sees what you *are* — "Wizard", not "mage" — since advancing is
                // exactly the sort of thing worth being seen doing.
                'job' => $player['job'] ?? $player['class'],
                'job_name' => $player['job_name'] ?? Jobs::name($player['class']),
                'level' => $player['level'],
                'alive' => $player['alive'],
            ];
        }

        return [
            'seed' => $state['seed'] ?? 0,
            'depth' => $state['depth'] ?? 1,
            'max_depth' => self::MAX_DEPTH,
            'players' => $players,
            // The character sheet, for the one person entitled to it.
            'me' => $me !== null ? ($state['players'][$me] ?? null) : null,
            'log' => array_slice($state['log'] ?? [], -6),
            'winner' => $state['winner'] ?? null,
        ];
    }

    // --- the moves ---

    /**
     * The floor's takings, banked.
     *
     * Called in batches (a few seconds of killing at a time), never per monster — one round trip
     * per skeleton would be one round trip per skeleton. The character row is the truth; the
     * copy in `state` is so the party can see each other's level without four extra queries.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function progress(array $state, int $id, array $payload): array
    {
        $xp = max(0, min(self::PROGRESS_XP_CAP, (int) ($payload['xp'] ?? 0)));
        $gold = max(0, min(self::PROGRESS_GOLD_CAP, (int) ($payload['gold'] ?? 0)));

        if ($xp === 0 && $gold === 0) {
            return $state;
        }

        $character = $this->characterOf($state, $id);
        $was = $character->level;

        $character->xp += $xp;
        $character->gold += $gold;
        $character->level = $this->levelFor($character->xp);

        // A level is four attribute points, a skill point, and a bigger constitution — the hero
        // improves whether or not you ever open the sheet. The two currencies stay separate on
        // purpose: spending one on the other would collapse both choices into one.
        if ($character->level > $was) {
            $gained = $character->level - $was;
            $stats = $character->stats;
            $stats['unspent'] = ($stats['unspent'] ?? 0) + 4 * $gained;
            $character->stats = $stats;
            $character->skill_points += (int) config('arpg.skill_points_per_level', 1) * $gained;
            $state['log'][] = $this->nameOf($state, $id)." reaches level {$character->level}.";
        }

        $character->last_played_at = now();
        $character->save();

        return $this->trimLog($this->sync($state, $id, $character));
    }

    /**
     * An item off the floor and into the bag.
     *
     * The item was rolled on the client — affixes are the engine's business, not the server's —
     * so what happens here is sanitising, not generating: known keys only, numbers clamped, and
     * a bag that can fill up.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function loot(array $state, int $id, array $payload): array
    {
        $item = $this->cleanItem($payload['item'] ?? null);

        $character = $this->characterOf($state, $id);
        $inventory = $character->inventory;

        if (count($inventory) >= self::INVENTORY_LIMIT) {
            throw ValidationException::withMessages(['item' => 'Your pack is full.']);
        }

        $inventory[] = $item;
        $character->inventory = $inventory;
        $character->save();

        return $this->sync($state, $id, $character);
    }

    /**
     * Wear something you're carrying. What was in the slot goes back in the bag.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function equip(array $state, int $id, array $payload): array
    {
        $index = (int) ($payload['index'] ?? -1);

        $character = $this->characterOf($state, $id);
        $inventory = array_values($character->inventory);
        $item = $inventory[$index] ?? null;

        if ($item === null) {
            throw ValidationException::withMessages(['index' => 'You are not carrying that.']);
        }

        $slot = $item['slot'];
        $equipment = $character->equipment;

        array_splice($inventory, $index, 1);

        // A straight swap: the old piece is never destroyed, it goes back on your belt.
        if (isset($equipment[$slot])) {
            $inventory[] = $equipment[$slot];
        }

        $equipment[$slot] = $item;

        $character->inventory = $inventory;
        $character->equipment = $equipment;
        $character->save();

        return $this->sync($state, $id, $character);
    }

    /**
     * Leave something behind — the only cure for a full pack.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function drop(array $state, int $id, array $payload): array
    {
        $index = (int) ($payload['index'] ?? -1);

        $character = $this->characterOf($state, $id);
        $inventory = array_values($character->inventory);

        if (! isset($inventory[$index])) {
            throw ValidationException::withMessages(['index' => 'You are not carrying that.']);
        }

        array_splice($inventory, $index, 1);
        $character->inventory = $inventory;
        $character->save();

        return $this->sync($state, $id, $character);
    }

    /**
     * Spend a point without leaving the dungeon — you levelled two rooms ago and want the skill
     * now, not after the run.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function learnInRun(array $state, int $id, array $payload): array
    {
        $character = $this->learn($this->characterOf($state, $id), (string) ($payload['skill'] ?? ''));

        return $this->sync($state, $id, $character);
    }

    /**
     * Take your second job without walking out of the dungeon — it's an event, and it should
     * happen where you earned it.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function advanceInRun(array $state, int $id): array
    {
        $character = $this->advance($this->characterOf($state, $id));
        $state['log'][] = $this->nameOf($state, $id).' is now a '.Jobs::name($character->job).'.';

        return $this->trimLog($this->sync($state, $id, $character));
    }

    /**
     * Spend a skill point: learn something new, or push something you have one level higher.
     *
     * Every rule about a hero's skills is in this one method, because "may I learn this" is a
     * question with a single right answer wherever it's asked — from inside a run or from the
     * character screen, both of which come through here.
     *
     * The inheritance cap is the interesting one. Learning outside your own class is deliberately
     * allowed: a swordsman who took a healing prayer is a *choice*, and the whole point of a
     * skill system is that two swordsmen can differ. But without a ceiling everyone converges on
     * the same best half-dozen skills and the eight classes become eight costumes — so a hero may
     * hold {@see config('arpg.foreign_skill_limit')} foreign skills, three by default, and the
     * cap counts *distinct* skills. Raising one you already borrowed is free of it: the limit is
     * on breadth, not on how far you commit to what you've borrowed.
     */
    public function learn(ArpgCharacter $character, string $skillId): ArpgCharacter
    {
        $skill = Skills::find($skillId);

        if ($skill === null) {
            throw ValidationException::withMessages(['skill' => 'There is no such skill.']);
        }

        if ($character->skill_points < 1) {
            throw ValidationException::withMessages(['skill' => 'You have no skill points to spend.']);
        }

        if ($character->level < $skill['level']) {
            throw ValidationException::withMessages([
                'skill' => "{$skill['name']} opens at character level {$skill['level']}.",
            ]);
        }

        $job = $character->job ?? $character->class;
        $tier = Skills::tier($skillId);

        // Second-tier skills need a second job — your own *or* somebody else's. Advancement is
        // what buys the whole tier; without this a level 40 mage could borrow a wizard's Meteor
        // and never advance, which makes the ceremony pointless.
        if ($tier > Jobs::tier($job)) {
            throw ValidationException::withMessages([
                'skill' => 'You have to take a second job before you can learn second-job skills.',
            ]);
        }

        $skills = $character->skills;
        $known = $skills[$skillId] ?? 0;
        $max = (int) config('arpg.max_skill_level', 10);

        if ($known >= $max) {
            throw ValidationException::withMessages(['skill' => "{$skill['name']} is already at its limit."]);
        }

        // The cap bites only on the *first* point in a borrowed skill — after that you already
        // own it, and pushing it further is no wider a spread than it was a moment ago. It's
        // counted per tier, so advancing hands you a fresh allowance rather than making you
        // regret the ones you took at level 10.
        if ($known === 0 && ! Skills::isOwn($skillId, $job)) {
            $limits = (array) config('arpg.foreign_skill_limits', []);
            $limit = (int) ($limits[$tier] ?? 3);

            if (Skills::foreignCount($skills, $job, $tier) >= $limit) {
                $which = $tier > 1 ? 'second-job ' : '';
                throw ValidationException::withMessages([
                    'skill' => "You can only learn {$limit} {$which}skills from other classes.",
                ]);
            }
        }

        $skills[$skillId] = $known + 1;

        $character->skills = $skills;
        $character->skill_points -= 1;
        $character->save();

        return $character;
    }

    /**
     * You went down.
     *
     * Death costs gold, not levels — the genre's bargain, and the reason a bad floor stings
     * without undoing an evening. A party can pick you back up ({@see revive}); alone, you go
     * back to the portal and the run ends when there's nobody left standing.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function died(array $state, int $id): array
    {
        if (! ($state['players'][$id]['alive'] ?? false)) {
            return $state;
        }

        $character = $this->characterOf($state, $id);
        $lost = (int) floor($character->gold * 0.25);
        $character->gold -= $lost;
        $character->save();

        $state['players'][$id]['alive'] = false;
        $state['log'][] = $this->nameOf($state, $id).' has fallen'.($lost > 0 ? ", losing {$lost} gold." : '.');

        return $this->settle($this->sync($state, $id, $character));
    }

    /**
     * Back on your feet — a companion drags you up, or you walk back in from town.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function revive(array $state, int $id): array
    {
        $state['players'][$id]['alive'] = true;
        $state['log'][] = $this->nameOf($state, $id).' is back on their feet.';

        return $this->trimLog($state);
    }

    /**
     * Down the stairs. The floor changes for everybody at once — a party is a party.
     *
     * Anyone standing can call it, because waiting for a unanimous click at the stairs is how
     * co-op sessions die. The dead come with you, upright: the stairwell is the game's mercy.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function descend(array $state, int $id): array
    {
        if (($state['winner'] ?? null) !== null) {
            throw ValidationException::withMessages(['action' => 'This run is over.']);
        }

        if (! ($state['players'][$id]['alive'] ?? false)) {
            throw ValidationException::withMessages(['action' => 'The dead do not lead the way.']);
        }

        $state['depth'] = ($state['depth'] ?? 1) + 1;

        foreach (array_keys($state['players']) as $pid) {
            $state['players'][$pid]['alive'] = true;

            $character = $this->characterOf($state, (int) $pid);
            // How deep this hero has ever been — what they'll be offered next time they play.
            $character->depth = max($character->depth, $state['depth']);
            $character->last_played_at = now();
            $character->save();
            $state = $this->sync($state, (int) $pid, $character);
        }

        if ($state['depth'] > self::MAX_DEPTH) {
            $state['winner'] = 'party';
            $state['log'][] = 'The Labyrinth is beaten. You climb back into the light.';

            return $this->trimLog($state);
        }

        $state['log'][] = "You descend to floor {$state['depth']}.";

        return $this->trimLog($state);
    }

    /**
     * Walk out. Your hero keeps everything — leaving a run is not losing one.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function leave(array $state, int $id): array
    {
        $name = $this->nameOf($state, $id);
        unset($state['players'][$id]);
        $state['log'][] = $name.' leaves the dungeon.';

        // An empty dungeon is a finished run — otherwise the room is stuck with a portal nobody
        // is standing in, and the next proposal is the only way to clear it.
        if (($state['players'] ?? []) === []) {
            $state['winner'] = 'empty';
        }

        return $this->trimLog($state);
    }

    // --- keeping the two halves in step ---

    /**
     * Sit a user's current hero down in the run.
     *
     * Whichever character they last played, or a fresh warrior if they've never rolled one —
     * a portal you can't step through until you've filled in a form is a portal nobody uses.
     *
     * @return array<string, mixed>
     */
    private function seat(User $user): array
    {
        $character = $this->currentCharacter($user);

        return [
            'character_id' => $character->id,
            'name' => $character->name,
            'class' => $character->class,
            'job' => $character->job ?? $character->class,
            'job_name' => Jobs::name($character->job ?? $character->class),
            'level' => $character->level,
            'xp' => $character->xp,
            'gold' => $character->gold,
            'stats' => $character->stats,
            'skills' => $character->skills,
            'skill_points' => $character->skill_points,
            'advance_to' => $this->advancement($character),
            // Which attribute this hero's damage comes off — the engine needs it and only the
            // class knows it.
            'primary' => self::CLASSES[$character->class]['primary'] ?? 'strength',
            'equipment' => $character->equipment,
            'inventory' => $character->inventory,
            'alive' => true,
        ];
    }

    /**
     * The next job in this hero's line, if there is one — what they can become, and when.
     *
     * `ready` is the button's whole answer. Null when the line has run out, which today is every
     * second job and tomorrow won't be.
     *
     * @return array{id: string, name: string, level: int, ready: bool}|null
     */
    private function advancement(ArpgCharacter $character): ?array
    {
        $next = Jobs::next($character->job ?? $character->class);

        if ($next === null) {
            return null;
        }

        $required = Jobs::requiredLevel($next);

        return [
            'id' => $next,
            'name' => Jobs::name($next),
            'level' => $required,
            'ready' => $character->level >= $required,
        ];
    }

    /** The hero this player is currently taking into dungeons, rolled on the spot if need be. */
    public function currentCharacter(User $user): ArpgCharacter
    {
        $character = ArpgCharacter::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_played_at')
            ->orderByDesc('id')
            ->first();

        return $character ?? $this->roll($user, $user->name, self::DEFAULT_CLASS);
    }

    /** Roll a new hero. The starting attributes are the class; everything else is earned. */
    public function roll(User $user, string $name, string $class): ArpgCharacter
    {
        $definition = self::CLASSES[$class] ?? self::CLASSES['swordsman'];
        $stats = $definition['stats'];
        $stats['unspent'] = 0;

        // Nobody starts with no verbs: the class's opening skill comes free, at level 1.
        $starting = Skills::startingSkill($class);

        // Every column spelled out rather than left to the table's defaults: a model straight out
        // of create() carries only what was passed, so an unset `level` is null in *this* object
        // however sensibly the database would have filled it in on the way back.
        return ArpgCharacter::create([
            'user_id' => $user->id,
            'name' => $name,
            'class' => $class,
            // You start at the head of your own line; `job` moves, `class` never does.
            'job' => $class,
            'level' => 1,
            'xp' => 0,
            'gold' => 0,
            'stats' => $stats,
            'skills' => $starting ? [$starting => 1] : [],
            'skill_points' => 0,
            'inventory' => [],
            'equipment' => [],
            'depth' => 1,
            'last_played_at' => now(),
        ]);
    }

    /**
     * Take the next job in your line — mage to wizard, thief to assassin.
     *
     * Deliberately a thing you *do* rather than something that happens to you at the right level.
     * It's the one moment in a run of numbers going up that's a decision and an event, and a hero
     * silently becoming a wizard between two skeletons would waste it.
     *
     * The new job's opening skill comes free, exactly as the first one did at birth: advancement
     * should hand you something to press, not homework.
     */
    public function advance(ArpgCharacter $character): ArpgCharacter
    {
        $job = $character->job ?? $character->class;
        $next = Jobs::next($job);

        if ($next === null) {
            throw ValidationException::withMessages([
                'job' => 'There is nothing beyond '.Jobs::name($job).'. Yet.',
            ]);
        }

        $required = Jobs::requiredLevel($next);

        if ($character->level < $required) {
            throw ValidationException::withMessages([
                'job' => Jobs::name($next)." is earned at level {$required}.",
            ]);
        }

        $skills = $character->skills;
        $starting = Skills::startingSkill($next);

        if ($starting !== null && ! isset($skills[$starting])) {
            $skills[$starting] = 1;
        }

        $character->job = $next;
        $character->skills = $skills;
        $character->save();

        return $character;
    }

    /**
     * What level a given amount of experience is worth.
     *
     * A quadratic curve — level n costs 100·n² in total — so the first few levels come in a
     * floor or two and the later ones are a campaign, which is the shape the genre wants.
     */
    public function levelFor(int $xp): int
    {
        $level = (int) floor(sqrt(max(0, $xp) / 100)) + 1;

        return max(1, min(50, $level));
    }

    /** The character row behind a seated player. */
    private function characterOf(array $state, int $id): ArpgCharacter
    {
        $character = ArpgCharacter::find($state['players'][$id]['character_id'] ?? 0);

        if ($character === null) {
            throw ValidationException::withMessages(['action' => 'That hero is gone.']);
        }

        return $character;
    }

    /**
     * Copy a saved hero back into the run's state.
     *
     * The duplication is deliberate: the row is the truth, and this is the party's view of it,
     * so nobody needs four extra queries to see that you levelled.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function sync(array $state, int $id, ArpgCharacter $character): array
    {
        if (! isset($state['players'][$id])) {
            return $state;
        }

        $state['players'][$id] = array_merge($state['players'][$id], [
            'job' => $character->job ?? $character->class,
            'job_name' => Jobs::name($character->job ?? $character->class),
            // What they could become next, and whether they're allowed to yet — the whole of
            // what the advance button needs to know, decided here rather than re-derived by
            // every client from a job tree they'd have to be given.
            'advance_to' => $this->advancement($character),
            'level' => $character->level,
            'xp' => $character->xp,
            'gold' => $character->gold,
            'stats' => $character->stats,
            'skills' => $character->skills,
            'skill_points' => $character->skill_points,
            'equipment' => $character->equipment,
            'inventory' => $character->inventory,
        ]);

        return $state;
    }

    /** A run with nobody left standing is over. */
    private function settle(array $state): array
    {
        $standing = array_filter($state['players'] ?? [], static fn ($p) => $p['alive'] ?? false);

        if ($standing === [] && ($state['winner'] ?? null) === null) {
            $state['winner'] = 'dungeon';
            $state['log'][] = 'The party is dead. The Labyrinth keeps its floors.';
        }

        return $this->trimLog($state);
    }

    /**
     * Sanitise a client-rolled item.
     *
     * The engine invents items — a name, a rarity, a handful of affixes — and the server has no
     * opinion about what a good sword is. It has an opinion about a payload with ten thousand
     * affixes in it, so: known slots, known keys, clamped numbers, bounded strings.
     *
     * @return array<string, mixed>
     */
    private function cleanItem(mixed $raw): array
    {
        if (! is_array($raw)) {
            throw ValidationException::withMessages(['item' => 'That is not an item.']);
        }

        $slots = ['weapon', 'armour', 'helm', 'shield', 'ring', 'amulet'];
        $slot = (string) ($raw['slot'] ?? '');

        if (! in_array($slot, $slots, true)) {
            throw ValidationException::withMessages(['item' => 'That is not an item.']);
        }

        $affixes = [];
        foreach (['damage', 'armour', 'life', 'mana', 'strength', 'dexterity', 'magic', 'vitality'] as $key) {
            $value = (int) ($raw['affixes'][$key] ?? 0);
            if ($value !== 0) {
                $affixes[$key] = max(-999, min(999, $value));
            }
        }

        return [
            'name' => mb_substr(trim((string) ($raw['name'] ?? 'Curio')), 0, 40) ?: 'Curio',
            'slot' => $slot,
            'rarity' => in_array($raw['rarity'] ?? '', ['common', 'magic', 'rare', 'unique'], true)
                ? $raw['rarity']
                : 'common',
            'ilvl' => max(1, min(self::MAX_DEPTH, (int) ($raw['ilvl'] ?? 1))),
            'affixes' => $affixes,
        ];
    }

    private function nameOf(array $state, int $id): string
    {
        return $state['players'][$id]['name'] ?? 'Someone';
    }

    /** @return array<string, mixed> */
    private function trimLog(array $state): array
    {
        $state['log'] = array_slice($state['log'] ?? [], -30);

        return $state;
    }
}
