<?php

namespace App\Services\Commands\Handlers;

use App\Models\Channel;
use App\Models\User;
use App\Services\Commands\SlashCommand;
use App\Support\Commands\ParsedCommand;
use App\Support\Commands\SlashOutcome;

/**
 * `/8ball` — the toy that answers yes/no questions badly.
 *
 * The question is echoed back with the answer because the answer alone is meaningless three
 * messages later, and a chat log is read long after the moment.
 */
class EightBallCommand implements SlashCommand
{
    /** The classic twenty, kept whole: the mix of maybes is what makes it funny. */
    private const ANSWERS = [
        'It is certain.', 'It is decidedly so.', 'Without a doubt.', 'Yes — definitely.',
        'You may rely on it.', 'As I see it, yes.', 'Most likely.', 'Outlook good.',
        'Yes.', 'Signs point to yes.', 'Reply hazy, try again.', 'Ask again later.',
        'Better not tell you now.', 'Cannot predict now.', 'Concentrate and ask again.',
        "Don't count on it.", 'My reply is no.', 'My sources say no.',
        'Outlook not so good.', 'Very doubtful.',
    ];

    public function name(): string
    {
        return '8ball';
    }

    public function description(): string
    {
        return 'Ask the magic 8-ball a yes/no question.';
    }

    public function usage(): string
    {
        return '/8ball will it deploy cleanly?';
    }

    public function handle(Channel $channel, User $user, ParsedCommand $command): SlashOutcome
    {
        if ($command->args === '') {
            return SlashOutcome::note('Ask it something — `/8ball will it deploy cleanly?`');
        }

        $answer = self::ANSWERS[random_int(0, count(self::ANSWERS) - 1)];

        return SlashOutcome::say("🎱 *{$command->args}*\n**{$answer}**");
    }
}
