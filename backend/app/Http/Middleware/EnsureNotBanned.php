<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops a banned account using the app.
 *
 * Login already refuses them (see LoginUserAction), but a ban lands on people who are
 * *currently signed in* far more often than on people at the login screen, and their token
 * is valid for as long as it was before. Banning revokes the tokens we know about; this
 * catches anything issued in the same breath, and every route in one place rather than in
 * each controller.
 *
 * The body carries the reason under `ban_reason` so the client can show the same sentence
 * the login screen shows, rather than a bare "signed out".
 */
class EnsureNotBanned
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isBanned()) {
            return response()->json([
                'message' => $user->ban_reason ?: 'Your account has been blocked.',
                'banned' => true,
                'ban_reason' => $user->ban_reason,
            ], 403);
        }

        return $next($request);
    }
}
