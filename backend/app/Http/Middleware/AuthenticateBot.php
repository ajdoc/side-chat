<?php

namespace App\Http\Middleware;

use App\Models\Bot;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a bot from its bearer token and makes it the request's user.
 *
 * Bots don't hold Passport tokens: those are minted by logging in, and a bot has no
 * password to log in with. It presents the long-lived string its creator was shown once,
 * and this middleware trades the hash of that for the account behind it.
 *
 * Setting the user resolver — rather than introducing a `bot` auth guard — is what lets
 * everything downstream stay unaware. A controller calls `$request->user()` and gets a
 * User; SendMessageAction takes it and writes a message exactly as it would a person's.
 * The bot registration itself rides on the request as `bot` for the routes that need the
 * server scope.
 */
class AuthenticateBot
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            abort(401, 'Bot token required.');
        }

        // Exact match on the hash: no scan, no timing signal worth having, and a token
        // that doesn't exist is indistinguishable from one that was revoked.
        $bot = Bot::with('user')->where('token_hash', Bot::hashToken($token))->first();

        if ($bot === null || $bot->user === null) {
            abort(401, 'Invalid bot token.');
        }

        // Best-effort activity stamp for the management screen ("last seen 2 minutes ago"),
        // which is the only thing that reads it — so it's saved quietly and never blocks.
        $bot->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->attributes->set('bot', $bot);
        $request->setUserResolver(fn () => $bot->user);

        return $next($request);
    }
}
