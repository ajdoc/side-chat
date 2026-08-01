<?php

namespace App\Providers;

use App\Events\MessageSent;
use App\Listeners\NotifyBotsOfMessage;
use App\Search\LikeSearchDriver;
use App\Search\PostgresSearchDriver;
use App\Search\SearchDriver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Which search implementation the app gets, decided once, from the database it is
         * actually pointed at. Postgres is what production and the test suite run, so the
         * full-text path is the tested one; a SQLite dev database (see .env.example) gets
         * substring matching instead. Resolved lazily — this closure runs only for a
         * request that actually searches, so the connection isn't opened to answer a ping.
         */
        $this->app->singleton(SearchDriver::class, fn () => DB::connection()->getDriverName() === 'pgsql'
            ? new PostgresSearchDriver
            : new LikeSearchDriver);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $strict = ! $this->app->isProduction();

        // Any implicit lazy load (N+1) throws outside production, so relations must be
        // eager-loaded explicitly. Tests will surface anything we miss.
        Model::preventLazyLoading($strict);
        Model::preventSilentlyDiscardingAttributes($strict);

        // Bot webhooks ride on the message event rather than on the send path — see
        // NotifyBotsOfMessage for why. Registered explicitly: auto-discovery would work,
        // but a listener nobody can find by grepping for the event is a listener that gets
        // broken by accident.
        Event::listen(MessageSent::class, NotifyBotsOfMessage::class);
    }
}
