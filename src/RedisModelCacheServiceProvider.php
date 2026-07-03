<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache;

use Illuminate\Support\ServiceProvider;
use Sm_mE\RedisModelCache\Contracts\HashCacheService;
use Sm_mE\RedisModelCache\Contracts\ModelCacheService;
use Sm_mE\RedisModelCache\Contracts\ModelMatchStrategy;
use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;
use Sm_mE\RedisModelCache\Support\DefaultConnectionResolver;
use Sm_mE\RedisModelCache\Support\DefaultModelMatchStrategy;

class RedisModelCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/redis-model-cache.php', 'redis-model-cache');

        $this->app->singleton(RedisConnectionResolver::class, function ($app) {
            return new DefaultConnectionResolver(
                config('redis-model-cache.connection', 'cache')
            );
        });

        $this->app->bindIf(ModelMatchStrategy::class, DefaultModelMatchStrategy::class);

        $this->app->bind(RedisHelperService::class, function ($app, $params) {
            return new RedisHelperService(
                connectionResolver: $app->make(RedisConnectionResolver::class),
                ttl: $params['ttl'] ?? null,
            );
        });

        $this->app->bind(HashCacheService::class, function ($app, $params) {
            return $app->make(RedisHelperService::class, $params);
        });

        $this->app->bind(RedisModelService::class, function ($app, $params) {
            return new RedisModelService(
                connectionResolver: $app->make(RedisConnectionResolver::class),
                model_class: $params['model_class'],
                indexes: $params['indexes'] ?? [],
                sorted: $params['sorted'] ?? [],
                custom_indexes: $params['custom_indexes'] ?? [],
                ttl: $params['ttl'] ?? null,
                matchStrategy: $app->make(ModelMatchStrategy::class),
                connection: $params['connection'] ?? null,
            );
        });

        $this->app->bind(ModelCacheService::class, function ($app, $params) {
            return $app->make(RedisModelService::class, $params);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/redis-model-cache.php' => config_path('redis-model-cache.php'),
        ], 'redis-model-cache-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\MonitorCacheCommand::class,
            ]);
        }
    }
}
