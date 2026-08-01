<?php

namespace App\Services\Commands\Handlers;

use App\Models\Channel;
use App\Models\User;
use App\Services\Commands\SlashCommand;
use App\Support\Commands\ParsedCommand;
use App\Support\Commands\SlashOutcome;

/**
 * `/roll` — dice, for deciding who deploys on a Friday.
 *
 * Public rather than ephemeral, and that's the whole point: a roll only settles anything if
 * everybody watched it happen. A private roll is just a number you claim you got.
 *
 * The individual dice are shown alongside the total for the same reason.
 */
class RollCommand implements SlashCommand
{
    /** Enough to be silly with, low enough that nobody renders a wall of numbers. */
    private const MAX_DICE = 20;

    private const MAX_SIDES = 1000;

    public function name(): string
    {
        return 'roll';
    }

    public function description(): string
    {
        return 'Roll dice, where everyone can see.';
    }

    public function usage(): string
    {
        return '/roll [2d6]';
    }

    public function handle(Channel $channel, User $user, ParsedCommand $command): SlashOutcome
    {
        $spec = $command->args === '' ? '1d20' : strtolower($command->args);

        // `2d6`, `d20`, or a bare `6` meaning one of them.
        if (! preg_match('/^(?:(\d*)d)?(\d+)$/', trim($spec), $m)) {
            return SlashOutcome::note("I can't roll “{$command->args}”. Try `/roll 2d6`.");
        }

        $count = $m[1] === '' ? 1 : (int) $m[1];
        $sides = (int) $m[2];

        if ($count < 1 || $count > self::MAX_DICE || $sides < 2 || $sides > self::MAX_SIDES) {
            return SlashOutcome::note('Up to '.self::MAX_DICE.' dice, with 2 to '.self::MAX_SIDES.' sides each.');
        }

        $rolls = [];
        for ($i = 0; $i < $count; $i++) {
            $rolls[] = random_int(1, $sides);
        }

        $total = array_sum($rolls);
        $notation = "{$count}d{$sides}";

        // One die has no interesting breakdown — "**4** (1d6) — 4" reads as a stutter.
        $breakdown = $count > 1 ? ' — '.implode(' + ', $rolls) : '';

        return SlashOutcome::say("🎲 **{$total}** ({$notation}){$breakdown}");
    }
}
