<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache;

use Illuminate\Support\Facades\App;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\WorkerTickStarting;
use Sm_mE\RedisModelCache\Concerns\HasRedisModelCache;
use Sm_mE\RedisModelCache\Contracts\HashCacheService;
use Sm_mE\RedisModelCache\Contracts\ModelCacheService;
use Sm_mE\RedisModelCache\Contracts\ModelMatchStrategy;
use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;
use Sm_mE\RedisModelCache\Contracts\TenantResolverInterface;
use Sm_mE\RedisModelCache\Listeners\ObservabilitySubscriber;
use Sm_mE\RedisModelCache\Support\CacheManager;
use Sm_mE\RedisModelCache\Support\DefaultConnectionResolver;
use Sm_mE\RedisModelCache\Support\DefaultModelMatchStrategy;
use Sm_mE\RedisModelCache\Support\Observability;
use Sm_mE\RedisModelCache\Support\TenantResolvers\RequestTenantResolver;

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

        $this->app->bind(TenantResolverInterface::class, function ($app) {
            $resolverClass = config('redis-model-cache.multi_tenant.resolver');

            if ($resolverClass !== null && class_exists($resolverClass)) {
                return $app->make($resolverClass);
            }

            $strategy = config('redis-model-cache.multi_tenant.strategy', 'header');
            $key = config('redis-model-cache.multi_tenant.key', 'X-Tenant-ID');

            return new RequestTenantResolver(strategy: $strategy, key: $key);
        });

        $this->app->singleton(Observability::class);

        $this->app->singleton(CacheManager::class, function ($app) {
            return new CacheManager(
                connectionResolver: $app->make(RedisConnectionResolver::class),
                observability: $app->make(Observability::class),
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
                Console\DebugCommand::class,
                Console\WarmupCommand::class,
            ]);
        }

        $this->registerEventSubscribers();
        $this->registerLifecycleHooks();
        $this->validateConfiguration();
    }

    /**
     * Register request/worker lifecycle hooks for state cleanup.
     *
     * Prevents static state bleed across requests in long-lived
     * Octane workers and ensures Observability ring buffers are
     * bounded per-request in non-Octane environments.
     */
    protected function registerLifecycleHooks(): void
    {
        App::terminating(function (): void {
            HasRedisModelCache::flushRedisModelCacheProcessing();
        });

        // Octane worker lifecycle: flush between requests
        if (class_exists(WorkerTickStarting::class)) {
            $this->app->make('events')->listen(
                WorkerTickStarting::class,
                function (): void {
                    HasRedisModelCache::flushRedisModelCacheProcessing();
                    $this->app->make(Observability::class)->reset();
                }
            );
        }
    }

    protected function registerEventSubscribers(): void
    {
        if (! config('redis-model-cache.observability.dispatch_events', true)) {
            return;
        }

        $this->app->make('events')->subscribe(
            $this->app->make(ObservabilitySubscriber::class)
        );
    }

    protected function validateConfiguration(): void
    {
        $connection = config('redis-model-cache.connection');
        if (! config("database.redis.{$connection}")) {
            throw new \InvalidArgumentException(
                "Redis connection '{$connection}' is not defined in config/database.php. "
                .'Define it or change REDIS_MODEL_CACHE_CONNECTION.'
            );
        }

        $ttl = config('redis-model-cache.default_ttl');
        if ($ttl !== null && (! is_int($ttl) || $ttl < 0)) {
            throw new \InvalidArgumentException(
                'Invalid default_ttl: must be a positive integer or null. Got: '.var_export($ttl, true)
            );
        }

        $scanStrategy = config('redis-model-cache.scan_strategy');
        if ($scanStrategy !== 'scan') {
            throw new \InvalidArgumentException(
                "Invalid scan_strategy: only 'scan' is supported. Got: {$scanStrategy}"
            );
        }

        $scanCount = config('redis-model-cache.scan_count');
        if (! is_int($scanCount) || $scanCount < 1) {
            throw new \InvalidArgumentException(
                'Invalid scan_count: must be a positive integer. Got: '.var_export($scanCount, true)
            );
        }

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
