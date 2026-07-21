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
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
