<?php

namespace App\Services\Commands\Handlers;

use App\Jobs\PostWebLookup;
use App\Models\Channel;
use App\Models\User;
use App\Services\Commands\SlashCommand;
use App\Support\Commands\ParsedCommand;
use App\Support\Commands\SlashOutcome;

/**
 * `/web what is a merkle tree` — look something up without leaving the channel.
 *
 * Answers from DuckDuckGo's instant answers and Wikipedia, both free and keyless, so the
 * command adds no API key and no per-query cost to the app. It's good at facts and
 * definitions and has nothing useful to say about news or opinion — see {@see WebLookup}.
 *
 * Named `/web`, not `/search`, on purpose: this app already has a search palette and a
 * `/api/search` for finding messages, and a `/search` that went to the *internet* would
 * collide with what "search" already means everywhere else in the product.
 *
 * The acknowledgement is private and the answer is public — the same split `/remind` uses.
 * Asking is nobody else's business, but the answer arrives in a shared room and would look
 * like it came from nowhere if the question weren't visible alongside it (which is why the
 * answer repeats the query in its header).
 */
class WebCommand implements SlashCommand
{
    /** Long enough for a real question, short enough that nobody pastes an essay. */
    private const MAX_QUERY = 200;

    public function name(): string
    {
        return 'web';
    }

    public function description(): string
    {
        return 'Look something up on DuckDuckGo and Wikipedia.';
    }

    public function usage(): string
    {
        return '/web what is a merkle tree';
    }

    public function handle(Channel $channel, User $user, ParsedCommand $command): SlashOutcome
    {
        $query = trim($command->args);

        if ($query === '') {
            return SlashOutcome::note('Look up what? Try `/web what is a merkle tree`.');
        }

        if (mb_strlen($query) > self::MAX_QUERY) {
            return SlashOutcome::note('That query is too long — keep it under '.self::MAX_QUERY.' characters.');
        }

        // Off the request path: see PostWebLookup for why this can't run inline.
        PostWebLookup::dispatch($channel->id, $user->id, $query);

        return SlashOutcome::note("Looking up “{$query}” — the answer will land in the channel.");
    }
}
