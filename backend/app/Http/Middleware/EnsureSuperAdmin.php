<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The door to /api/admin.
 *
 * 404 rather than 403 for everyone who isn't a super admin: the panel's existence is not
 * something an ordinary account needs confirmed, and a 403 confirms it. Bots never pass —
 * a bot token is issued by a server owner, and site administration is not a thing a server
 * owner can hand out.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if(! $user || $user->is_bot || ! $user->isSuperAdmin(), 404);

        return $next($request);
    }
}
