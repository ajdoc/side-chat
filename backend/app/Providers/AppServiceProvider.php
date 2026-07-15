<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
    }
}
