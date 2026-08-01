<?php

use App\Console\Commands\PruneChunkedUploads;
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
    ->withCommands([PruneChunkedUploads::class])
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
        $middleware->trimStrings(except: ['tiles.*']);

        // Bots authenticate with their own long-lived token rather than a Passport one —
        // see App\Http\Middleware\AuthenticateBot.
        $middleware->alias(['auth.bot' => \App\Http\Middleware\AuthenticateBot::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
