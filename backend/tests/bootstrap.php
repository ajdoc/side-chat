<?php

require __DIR__.'/../vendor/autoload.php';

/*
 * docker-compose injects DB_HOST, DB_DATABASE, BROADCAST_CONNECTION etc. as REAL
 * environment variables, which Laravel resolves from $_SERVER / $_ENV.
 *
 * PHPUnit's <env force="true"> only calls putenv(), and Laravel's Env repository has
 * the putenv adapter disabled -- so the container's values would win and the suite
 * would run against the DEV database (RefreshDatabase drops every table!).
 *
 * Overwrite $_SERVER / $_ENV here, before the framework boots.
 */
$overrides = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'pgsql',
    'DB_HOST' => 'postgres',
    'DB_PORT' => '5432',
    'DB_DATABASE' => 'sidechat_testing',
    'DB_USERNAME' => 'sidechat',
    'DB_PASSWORD' => 'secret',
    'BROADCAST_CONNECTION' => 'null',
    'QUEUE_CONNECTION' => 'sync',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
];

foreach ($overrides as $key => $value) {
    $_SERVER[$key] = $value;
    $_ENV[$key] = $value;
    putenv("{$key}={$value}");
}
