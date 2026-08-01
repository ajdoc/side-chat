<?php

namespace App\Services\Commands\Handlers;

use App\Models\Channel;
use App\Models\User;
use App\Services\Commands\SlashCommand;
use App\Support\Commands\ParsedCommand;
use App\Support\Commands\SlashOutcome;

/**
 * `/shrug` — appends the shrug nobody can remember how to type.
 */
class ShrugCommand implements SlashCommand
{
    /**
     * The backslash is escaped so markdown rendering doesn't eat it (and with it the
     * shrug's left arm), which is the failure mode every implementation of this hits once.
     */
    private const SHRUG = '¯\\\\_(ツ)_/¯';

    public function name(): string
    {
        return 'shrug';
    }

    public function description(): string
    {
        return 'Append ¯\_(ツ)_/¯ to your message.';
    }

    public function usage(): string
    {
        return '/shrug [message]';
    }

    public function handle(Channel $channel, User $user, ParsedCommand $command): SlashOutcome
    {
        return SlashOutcome::say(trim($command->args.' '.self::SHRUG));
    }
}
