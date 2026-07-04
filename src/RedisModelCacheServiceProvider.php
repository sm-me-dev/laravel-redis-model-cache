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

        $this->app->bind(RedisModelService::class, function ($app, array $params) {
            return new RedisModelService(
                connectionResolver: $app->make(RedisConnectionResolver::class),
                model_class: $params['model_class'] ?? '',
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

        // Validate configuration
        $this->validateConfiguration();
    }

    /**
     * Validate the package configuration.
     *
     * @throws \InvalidArgumentException If configuration is invalid
     */
    protected function validateConfiguration(): void
    {
        // Validate Redis connection exists
        $connection = config('redis-model-cache.connection');
        if (! config("database.redis.{$connection}")) {
            throw new \InvalidArgumentException(
                "Redis connection '{$connection}' is not defined in config/database.php. "
                .'Define it or change REDIS_MODEL_CACHE_CONNECTION.'
            );
        }

        // Validate TTL
        $ttl = config('redis-model-cache.default_ttl');
        if ($ttl !== null && (! is_int($ttl) || $ttl < 0)) {
            throw new \InvalidArgumentException(
                'Invalid default_ttl: must be a positive integer or null. Got: '.var_export($ttl, true)
            );
        }

        // Validate scan strategy
        $scanStrategy = config('redis-model-cache.scan_strategy');
        if ($scanStrategy !== 'scan') {
            throw new \InvalidArgumentException(
                "Invalid scan_strategy: only 'scan' is supported. Got: {$scanStrategy}"
            );
        }

        // Validate scan count
        $scanCount = config('redis-model-cache.scan_count');
        if (! is_int($scanCount) || $scanCount < 1) {
            throw new \InvalidArgumentException(
                'Invalid scan_count: must be a positive integer. Got: '.var_export($scanCount, true)
            );
        }

        // Validate stampede protection config
        if (config('redis-model-cache.stampede_protection.enabled')) {
            $lockTimeout = config('redis-model-cache.stampede_protection.lock_timeout');
            $waitTimeout = config('redis-model-cache.stampede_protection.wait_timeout');
            $waitInterval = config('redis-model-cache.stampede_protection.wait_interval');

            if (! is_int($lockTimeout) || $lockTimeout < 1) {
                throw new \InvalidArgumentException(
                    'Invalid stampede_protection.lock_timeout: must be a positive integer. Got: '.var_export($lockTimeout, true)
                );
            }

            if (! is_int($waitTimeout) || $waitTimeout < 1) {
                throw new \InvalidArgumentException(
                    'Invalid stampede_protection.wait_timeout: must be a positive integer. Got: '.var_export($waitTimeout, true)
                );
            }

            if (! is_int($waitInterval) || $waitInterval < 1) {
                throw new \InvalidArgumentException(
                    'Invalid stampede_protection.wait_interval: must be a positive integer. Got: '.var_export($waitInterval, true)
                );
            }
        }

        // Validate stale-while-revalidate config
        if (config('redis-model-cache.stale_while_revalidate.enabled')) {
            $gracePeriod = config('redis-model-cache.stale_while_revalidate.grace_period');
            $queue = config('redis-model-cache.stale_while_revalidate.queue');

            if (! is_int($gracePeriod) || $gracePeriod < 1) {
                throw new \InvalidArgumentException(
                    'Invalid stale_while_revalidate.grace_period: must be a positive integer. Got: '.var_export($gracePeriod, true)
                );
            }

            if (! is_string($queue) || trim($queue) === '') {
                throw new \InvalidArgumentException(
                    'Invalid stale_while_revalidate.queue: must be a non-empty string. Got: '.var_export($queue, true)
                );
            }
        }
    }
}
