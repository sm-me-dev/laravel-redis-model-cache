<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\WorkerTickStarting;
use Sm_mE\RedisModelCache\Contracts\HashCacheService;
use Sm_mE\RedisModelCache\Contracts\ModelCacheService;
use Sm_mE\RedisModelCache\Contracts\ModelMatchStrategy;
use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;
use Sm_mE\RedisModelCache\Contracts\TenantResolverInterface;
use Sm_mE\RedisModelCache\Listeners\ObservabilitySubscriber;
use Sm_mE\RedisModelCache\Support\CacheManager;
use Sm_mE\RedisModelCache\Support\Configuration;
use Sm_mE\RedisModelCache\Support\DefaultConnectionResolver;
use Sm_mE\RedisModelCache\Support\DefaultModelMatchStrategy;
use Sm_mE\RedisModelCache\Support\Observability;
use Sm_mE\RedisModelCache\Support\RedisModelCacheState;
use Sm_mE\RedisModelCache\Support\TenantResolvers\RequestTenantResolver;

class RedisModelCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/redis-model-cache.php', 'redis-model-cache');

        $this->app->singleton(RedisConnectionResolver::class, function ($app) {
            return new DefaultConnectionResolver(
                Configuration::fromConfig()->connection
            );
        });

        $this->app->bind(TenantResolverInterface::class, function ($app) {
            $config = Configuration::fromConfig();

            if ($config->multiTenantResolver !== null && class_exists($config->multiTenantResolver)) {
                return $app->make($config->multiTenantResolver);
            }

            return new RequestTenantResolver(
                strategy: $config->multiTenantStrategy,
                key: $config->multiTenantKey,
            );
        });

        $this->app->scoped(RedisModelCacheState::class);

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
     * Uses the scoped RedisModelCacheState instead of static trait state,
     * making the package safe for Octane workers. The scoped binding is
     * automatically reset between requests in Octane.
     */
    protected function registerLifecycleHooks(): void
    {
        App::terminating(function (): void {
            if ($this->app->resolved(RedisModelCacheState::class)) {
                $this->app->make(RedisModelCacheState::class)->flush();
            }
        });

        // Octane worker lifecycle: flush between requests
        if (class_exists(WorkerTickStarting::class)) {
            $this->app->make('events')->listen(
                WorkerTickStarting::class,
                function (): void {
                    if ($this->app->resolved(RedisModelCacheState::class)) {
                        $this->app->make(RedisModelCacheState::class)->flush();
                    }
                    $this->app->make(Observability::class)->reset();
                }
            );
        }
    }

    protected function registerEventSubscribers(): void
    {
        if (! Configuration::fromConfig()->observabilityDispatchEvents) {
            return;
        }

        $this->app->make('events')->subscribe(
            $this->app->make(ObservabilitySubscriber::class)
        );
    }

    protected function validateConfiguration(): void
    {
        try {
            $connection = config('redis-model-cache.connection');
            if ($connection !== null && ! config("database.redis.{$connection}")) {
                throw new \InvalidArgumentException(
                    "Redis connection '{$connection}' is not defined in config/database.php. "
                    .'Define it or change REDIS_MODEL_CACHE_CONNECTION.'
                );
            }
        } catch (\InvalidArgumentException $e) {
            Log::warning($e->getMessage());
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

        $configVersion = config('redis-model-cache.config_version');
        if ($configVersion !== '2.10.0') {
            Log::warning(
                "Published configuration version mismatch. Expected '2.5', got "
                .var_export($configVersion, true)
                .'. Please re-publish your configuration file using: '
                .'php artisan vendor:publish --tag=redis-model-cache-config --force'
            );
        }
    }
}
