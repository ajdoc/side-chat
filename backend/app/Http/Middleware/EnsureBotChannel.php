<?php

namespace App\Http\Middleware;

use App\Models\Bot;
use App\Models\Channel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Is this channel in the server that issued this token?"
 *
 * The second half of a bot's reach, and the half membership alone doesn't cover. A bot's account
 * can be on the roster of several servers — it is a `User` like any other — so a leaked token
 * checked only against membership would reach every channel that account had ever been added to.
 * {@see \App\Http\Requests\Bot\BotSendMessageRequest} makes this check inline for the send path;
 * this is the same rule as middleware, so the app routes can reuse the *existing* controllers
 * rather than growing bot-shaped copies of each.
 *
 * That reuse is the point. `bot/channels/{channel}/kanban/cards` and
 * `channels/{channel}/kanban/cards` are the same controller, the same request class and the same
 * membership gate — the only difference is who authenticated and this one extra question. A
 * parallel set of bot controllers would be the same logic twice, drifting.
 */
class EnsureBotChannel
{
    public function handle(Request $request, Closure $next): Response
    {
        $bot = $request->attributes->get('bot');
        $channel = $request->route('channel');

        if (! $bot instanceof Bot || ! $channel instanceof Channel) {
            abort(403);
        }

        // A discussion carries its container's `server_id`, so this covers both without walking
        // to the parent.
        abort_unless($channel->server_id !== null && $channel->server_id === $bot->server_id, 403);

        return $next($request);
    }
}
