<?php

namespace App\Actions\Bot;

use App\Models\Bot;
use Illuminate\Support\Facades\DB;

final class DeleteBotAction
{
    /**
     * Removes a bot from its server: the registration goes, the token stops working, and it
     * leaves the roster.
     *
     * The *account* deliberately stays. `messages.user_id` cascades on delete, so deleting
     * the user would take every message the bot ever posted with it — months of a channel's
     * history disappearing because someone retired an integration. Keeping the account means
     * the history reads exactly as it did, authored by a bot that is no longer here, which
     * is the truth of what happened.
     *
     * What's left behind can do nothing: it has no password to log in with, no token (the
     * row holding its hash is gone), and no membership anywhere.
     */
    public function handle(Bot $bot): void
    {
        DB::transaction(function () use ($bot): void {
            $bot->server->members()->detach($bot->user_id);
            $bot->delete();
        });
    }
}
