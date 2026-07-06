<?php

declare(strict_types=1);

/**
 * Benchmark Bootstrap
 *
 * Bootstraps a minimal Laravel application with Redis config, so benchmark
 * scripts can resolve RedisModelService from the service container.
 *
 * Usage:
 *   require __DIR__.'/bootstrap.php';
 *   $service = app(RedisModelService::class, [...] );
 */

use Illuminate\Contracts\Console\Kernel;
use Sm_mE\RedisModelCache\RedisModelCacheServiceProvider;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../workbench/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Ensure a Redis connection exists for the cache connection
$app['config']->set('database.redis.cache', [
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'port' => env('REDIS_PORT', 6379),
    'database' => env('REDIS_CACHE_DB', 1),
]);

// Manually set config values that the service provider would set via mergeConfigFrom
$packageConfig = require __DIR__.'/../config/redis-model-cache.php';
foreach ($packageConfig as $key => $value) {
    $app['config']->set('redis-model-cache.'.$key, $value);
}

// Register the service provider
$app->register(RedisModelCacheServiceProvider::class);
