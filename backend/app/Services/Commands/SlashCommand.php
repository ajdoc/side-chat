<?php

namespace App\Services\Commands;

use App\Models\Channel;
use App\Models\User;
use App\Support\Commands\ParsedCommand;
use App\Support\Commands\SlashOutcome;

/**
 * One built-in slash command.
 *
 * Mirrors the widget handlers next door (App\Services\Widgets\WidgetHandler): a command is
 * a class that names itself and knows what to do, and the registry is a list of them. The
 * three description methods aren't decoration — they're what `/help` and the composer's
 * autocomplete are built from, so a command that exists is always a command that documents
 * itself, and there is no separate list to forget to update.
 */
interface SlashCommand
{
    /** The verb, without the slash. Lowercase — the parser lowercases what it matches. */
    public function name(): string;

    /** One line, shown in `/help` and in the composer's autocomplete. */
    public function description(): string;

    /** How to call it, e.g. `/roll 2d6`. Shown beside the description. */
    public function usage(): string;

    public function handle(Channel $channel, User $user, ParsedCommand $command): SlashOutcome;
}
