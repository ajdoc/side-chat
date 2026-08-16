<?php

use App\Console\Commands\DrawGiveaways;
use App\Console\Commands\PruneBotAuditLog;
use App\Console\Commands\PruneChunkedUploads;
use App\Console\Commands\RunBotSchedules;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Command *classes* need naming: passing routes/console.php above as the commands path
    // means app/Console/Commands isn't scanned for them.
    ->withCommands([
        PruneChunkedUploads::class,
        RunBotSchedules::class,
        DrawGiveaways::class,
        PruneBotAuditLog::class,
    ])
    /*
     * Listeners are registered by hand, in AppServiceProvider, and *only* by hand.
     *
     * Discovery is on by default and scans app/Listeners, which means a listener that is
     * also registered explicitly — as both of ours are, deliberately, so they can be found
     * by grepping for the event — gets registered twice and therefore runs twice. That is
     * not a theoretical problem: it was delivering every bot webhook twice.
     *
     * Off rather than dropping the explicit calls, because "which listeners does this event
     * have" should be answerable by reading a file rather than by knowing a convention.
     */
    ->withEvents(discover: false)
    // Authenticate the /broadcasting/auth endpoint with the Passport token guard
    // (the SPA uses Bearer tokens, not session cookies).
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:api']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * A Side Space's map rows are not text. They're a grid of tile characters, and one of
         * those characters is a space — the void outside a room that isn't a rectangle. Trimming
         * a row therefore eats the corners off any round room and leaves the grid a different
         * width than it says it is, which the map validator correctly refuses.
         *
         * So the tile rows, and only the tile rows, keep their whitespace. See
         * App\Support\SideSpace\Tiles.
         */
        /*
         * An SDP is not text either, and it fails in a nastier way than the map does.
         *
         * A session description is a line-oriented protocol whose every line, including the
         * last, is terminated by CRLF. Trimming eats that final terminator, and the far end
         * rejects the whole thing — Cloudflare answers `invalid_session_description: Unable
         * to parse SDP ... common issue: missing termination at the end`, which is a long way
         * from "your web framework tidied the request". Nothing in the browser or the relay
         * looks wrong; the bytes are simply not what was sent.
         */
        $middleware->trimStrings(except: [
            'tiles.*',
            'session_description.sdp',
            'sessionDescription.sdp',
        ]);

        // Bots authenticate with their own long-lived token rather than a Passport one —
        // see App\Http\Middleware\AuthenticateBot.
        $middleware->alias([
            'auth.bot' => \App\Http\Middleware\AuthenticateBot::class,
            // The admin panel's door. See EnsureSuperAdmin for why it 404s rather than 403s.
            'admin' => \App\Http\Middleware\EnsureSuperAdmin::class,
        ]);

        // A ban has to bite tokens that were already issued, so it's checked on every API
        // request rather than only at login.
        $middleware->appendToGroup('api', \App\Http\Middleware\EnsureNotBanned::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
