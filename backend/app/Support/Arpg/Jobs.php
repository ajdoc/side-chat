<?php

namespace App\Support\Arpg;

/**
 * The job tree: what a hero starts as, and what they can become.
 *
 * A *class* is the line you picked at birth and never change — a mage is a mage forever. A *job*
 * is where you are along that line: mage at level 1, wizard from thirty. The distinction is worth
 * two words because everything hangs off it. Your class is your identity and decides your
 * attributes; your job decides which skills are *yours* rather than borrowed, and a hero's own
 * skills are every job in their line, not only the one they're standing in. A wizard doesn't
 * forget Firebolt.
 *
 * ## Why a graph rather than a `tier` integer
 *
 * Because the third job is coming, and when it does the only thing that should change is this
 * file: another row per line with `advances_from` pointing back. Nothing that reads a job asks
 * "is this tier 2", it asks "is this in my line" ({@see line}) or "what's next" ({@see next}), and
 * both keep working with four tiers as readily as two.
 */
class Jobs
{
    /**
     * Every job, keyed by id.
     *
     * `advances_to` is the next job in the line, or null at the end of what's built. `tier` is
     * how far along it is, which is what the per-tier inheritance cap and the level gates count.
     *
     * @var array<string, array{name: string, tier: int, advances_from: ?string, advances_to: ?string}>
     */
    private const JOBS = [
        // --- tier 1: the eight you can roll ---
        'swordsman' => ['name' => 'Swordsman', 'tier' => 1, 'advances_from' => null, 'advances_to' => 'knight'],
        'crusader' => ['name' => 'Crusader', 'tier' => 1, 'advances_from' => null, 'advances_to' => 'paladin'],
        'archer' => ['name' => 'Archer', 'tier' => 1, 'advances_from' => null, 'advances_to' => 'ranger'],
        'thief' => ['name' => 'Thief', 'tier' => 1, 'advances_from' => null, 'advances_to' => 'assassin'],
        'mage' => ['name' => 'Mage', 'tier' => 1, 'advances_from' => null, 'advances_to' => 'wizard'],
        'priest' => ['name' => 'Priest', 'tier' => 1, 'advances_from' => null, 'advances_to' => 'bishop'],
        'necromancer' => ['name' => 'Necromancer', 'tier' => 1, 'advances_from' => null, 'advances_to' => 'warlock'],
        'druid' => ['name' => 'Druid', 'tier' => 1, 'advances_from' => null, 'advances_to' => 'archdruid'],

        // --- tier 2: what they become at thirty ---
        'knight' => ['name' => 'Knight', 'tier' => 2, 'advances_from' => 'swordsman', 'advances_to' => null],
        'paladin' => ['name' => 'Paladin', 'tier' => 2, 'advances_from' => 'crusader', 'advances_to' => null],
        'ranger' => ['name' => 'Ranger', 'tier' => 2, 'advances_from' => 'archer', 'advances_to' => null],
        'assassin' => ['name' => 'Assassin', 'tier' => 2, 'advances_from' => 'thief', 'advances_to' => null],
        'wizard' => ['name' => 'Wizard', 'tier' => 2, 'advances_from' => 'mage', 'advances_to' => null],
        'bishop' => ['name' => 'Bishop', 'tier' => 2, 'advances_from' => 'priest', 'advances_to' => null],
        'warlock' => ['name' => 'Warlock', 'tier' => 2, 'advances_from' => 'necromancer', 'advances_to' => null],
        'archdruid' => ['name' => 'Archdruid', 'tier' => 2, 'advances_from' => 'druid', 'advances_to' => null],
    ];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::JOBS;
    }

    /** @return array<string, mixed>|null */
    public static function find(string $id): ?array
    {
        return self::JOBS[$id] ?? null;
    }

    public static function exists(string $id): bool
    {
        return isset(self::JOBS[$id]);
    }

    public static function name(string $id): string
    {
        return self::JOBS[$id]['name'] ?? ucfirst($id);
    }

    public static function tier(string $id): int
    {
        return self::JOBS[$id]['tier'] ?? 1;
    }

    /** The jobs you can roll — the start of every line. */
    public static function starting(): array
    {
        return array_keys(array_filter(self::JOBS, static fn ($job) => $job['advances_from'] === null));
    }

    /** What this job becomes next, or null at the end of the line as built. */
    public static function next(string $id): ?string
    {
        return self::JOBS[$id]['advances_to'] ?? null;
    }

    /**
     * The whole line up to and including this job — everything a hero of it counts as their own.
     *
     * Walking backwards through `advances_from` rather than reading a stored history: the line is
     * a fact about the tree, and a hero who is a wizard has necessarily been a mage.
     *
     * @return array<int, string>
     */
    public static function line(string $id): array
    {
        $line = [];
        $at = $id;

        while ($at !== null && isset(self::JOBS[$at])) {
            array_unshift($line, $at);
            $at = self::JOBS[$at]['advances_from'];
        }

        return $line;
    }

    /** The level this job opens at — the gate on advancing into it. */
    public static function requiredLevel(string $id): int
    {
        $levels = (array) config('arpg.advancement_levels', []);

        return (int) ($levels[self::tier($id)] ?? 1);
    }
}
