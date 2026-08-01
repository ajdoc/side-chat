<?php

namespace App\Services\Commands\Handlers;

use App\Jobs\PostReminder;
use App\Models\Channel;
use App\Models\User;
use App\Services\Commands\SlashCommand;
use App\Support\Commands\ParsedCommand;
use App\Support\Commands\SlashOutcome;
use Carbon\CarbonInterval;

/**
 * `/remind 20m check the migration` — a nudge in this channel, later.
 *
 * The acknowledgement is ephemeral and the reminder itself is public. That asymmetry is
 * deliberate: setting a reminder is nobody else's business, but the reminder arrives in a
 * shared room, so it has to be visible to the people already there rather than appear to
 * come from nowhere. See PostReminder for where it's kept in the meantime.
 */
class RemindCommand implements SlashCommand
{
    /**
     * Anything longer belongs in the calendar app, which is built to survive a queue being
     * cleared. A month is already generous for something held in a job.
     */
    private const MAX_SECONDS = 30 * 24 * 60 * 60;

    private const UNITS = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];

    public function name(): string
    {
        return 'remind';
    }

    public function description(): string
    {
        return 'Nudge this channel about something later.';
    }

    public function usage(): string
    {
        return '/remind 20m check the migration';
    }

    public function handle(Channel $channel, User $user, ParsedCommand $command): SlashOutcome
    {
        $delay = $this->seconds($command->firstArg());
        $text = $command->restAfterFirst();

        if ($delay === null) {
            return SlashOutcome::note('When? Try `/remind 20m check the migration` — s, m, h or d.');
        }

        if ($text === '') {
            return SlashOutcome::note('Remind you about what? Try `/remind 20m check the migration`.');
        }

        if ($delay > self::MAX_SECONDS) {
            return SlashOutcome::note("That's further off than I can hold on to. A month is the limit — the calendar app is better for anything longer.");
        }

        PostReminder::dispatch($channel->id, $user->id, $text)->delay(now()->addSeconds($delay));

        $when = CarbonInterval::seconds($delay)->cascade()->forHumans(['short' => false]);

        return SlashOutcome::note("Okay — I'll remind the channel in {$when}.");
    }

    /** `90s`, `20m`, `2h`, `1d` → seconds. Null for anything else, including a bare number. */
    private function seconds(string $spec): ?int
    {
        if (! preg_match('/^(\d+)([smhd])$/i', $spec, $m)) {
            return null;
        }

        $amount = (int) $m[1];

        return $amount < 1 ? null : $amount * self::UNITS[strtolower($m[2])];
    }
}
