<?php

namespace App\Services\Commands\Handlers;

use App\Models\Channel;
use App\Models\User;
use App\Services\Commands\SlashCommand;
use App\Support\Commands\ParsedCommand;
use App\Support\Commands\SlashOutcome;

/**
 * `/me` — an emote. "Bob" + "_is rebuilding the container_".
 *
 * Note what it does *not* do: paste the author's name into the text. The IRC form
 * ("* Bob waves") would look right and be wrong here, because this app lets somebody go by
 * a different name in each place they're a member (see NicknameService) and a name baked
 * into a message body can never be one of those. Leaving it out costs nothing — the
 * timeline already draws the author above every message — and keeps the emote reading
 * correctly for everyone.
 */
class MeCommand implements SlashCommand
{
    public function name(): string
    {
        return 'me';
    }

    public function description(): string
    {
        return 'Do something, rather than say it.';
    }

    public function usage(): string
    {
        return '/me rebuilds the container';
    }

    public function handle(Channel $channel, User $user, ParsedCommand $command): SlashOutcome
    {
        if ($command->args === '') {
            return SlashOutcome::note('Do what? Try `/me rebuilds the container`.');
        }

        // Underscores rather than asterisks: a body full of `*` is more likely to collide
        // with something the author typed themselves.
        return SlashOutcome::say('_'.$command->args.'_');
    }
}
