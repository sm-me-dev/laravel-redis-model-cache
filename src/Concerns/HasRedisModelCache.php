<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Sm_mE\RedisModelCache\Invalidation\InvalidationManager;
use Sm_mE\RedisModelCache\RedisModelService;

trait HasRedisModelCache
{
    protected static function redisModelCacheConfig(): array
    {
        return [];
    }

    /** @var array<class-string, list<mixed>> */
    protected static array $redisModelCacheProcessing = [];

    /**
     * Tracks model keys deleted during current request cycle.
     * Prevents the saved event (fired by forceDelete) from re-caching a deleted model.
     *
     * @var array<class-string, list<mixed>>
     */
    protected static array $redisModelCacheDeletedInCycle = [];

    /**
     * Laravel Eloquent calls this via the booting trait convention.
     */
    public static function bootHasRedisModelCache(): void
    {
        static::saved(function (Model $model) {
            static::processRedisModelCacheSaved($model);
        });

        // Only register restored event if the model uses SoftDeletes trait
        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model) {
                static::processRedisModelCacheSaved($model);
            });
        }

        static::deleted(function (Model $model) {
            if (static::isRedisModelCacheProcessing($model)) {
                return;
            }

            static::markRedisModelCacheProcessing($model);

            try {
                static::resolveInvalidationManager()->handle('deleted', $model);
                static::markRedisModelCacheDeletedInCycle($model);
                static::touchRedisModelCacheParents($model);
            } finally {
                static::unmarkRedisModelCacheProcessing($model);
            }
        });

        /**
         * forceDelete fires forceDeleted -> deleted -> saved in a single cycle.
         * We mark the model as deleted-in-cycle so the subsequent saved event
         * does not re-cache the just-deleted model.
         */
        if (method_exists(static::class, 'forceDeleted')) {
            static::forceDeleted(function (Model $model) {
                if (static::isRedisModelCacheProcessing($model)) {
                    return;
                }

                static::markRedisModelCacheProcessing($model);

                try {
                    static::resolveInvalidationManager()->handle('deleted', $model);
                    static::markRedisModelCacheDeletedInCycle($model);
                    static::touchRedisModelCacheParents($model);
                } finally {
                    static::unmarkRedisModelCacheProcessing($model);
                }
            });
        }
    }

    protected static function processRedisModelCacheSaved(Model $model): void
    {
        if (static::isRedisModelCacheProcessing($model)) {
            return;
        }

        if (static::isRedisModelCacheDeletedInCycle($model)) {
            return;
        }

        static::markRedisModelCacheProcessing($model);

        try {
            static::resolveRedisModelCacheService()->store($model);
            static::resolveInvalidationManager()->handle('saved', $model);
            static::touchRedisModelCacheParents($model);
        } finally {
            static::unmarkRedisModelCacheProcessing($model);
        }
    }

    protected static function isRedisModelCacheProcessing(Model $model): bool
    {
        return in_array(
            $model->getKey(),
            static::$redisModelCacheProcessing[static::class] ?? [],
            true
        );
    }

    protected static function markRedisModelCacheProcessing(Model $model): void
    {
        $ids = static::$redisModelCacheProcessing[static::class] ?? [];
        $ids[] = $model->getKey();
        static::$redisModelCacheProcessing[static::class] = $ids;
    }

    protected static function unmarkRedisModelCacheProcessing(Model $model): void
    {
        $ids = static::$redisModelCacheProcessing[static::class] ?? [];
        $key = array_search($model->getKey(), $ids, true);

        if ($key !== false) {
            unset($ids[$key]);
            static::$redisModelCacheProcessing[static::class] = array_values($ids);
        }
    }

    protected static function isRedisModelCacheDeletedInCycle(Model $model): bool
    {
        return in_array(
            $model->getKey(),
            static::$redisModelCacheDeletedInCycle[static::class] ?? [],
            true
        );
    }

    protected static function markRedisModelCacheDeletedInCycle(Model $model): void
    {
        $ids = static::$redisModelCacheDeletedInCycle[static::class] ?? [];
        $ids[] = $model->getKey();
        static::$redisModelCacheDeletedInCycle[static::class] = $ids;
    }

    protected static function resolveRedisModelCacheService(): RedisModelService
    {
        $modelClass = static::class;

        $config = static::redisModelCacheConfig();

        $params = [
            'model_class' => $modelClass,
            'indexes' => $config['indexes'] ?? [],
            'sorted' => $config['sorted'] ?? [],
            'custom_indexes' => $config['custom_indexes'] ?? [],
            'ttl' => $config['ttl'] ?? null,
            'connection' => $config['connection'] ?? null,
        ];

        return app(RedisModelService::class, $params);
    }

    protected static function resolveInvalidationManager(): InvalidationManager
    {
        $service = static::resolveRedisModelCacheService();
        $config = static::redisModelCacheConfig();
        $invalidationConfig = config('redis-model-cache.invalidation', []);

        return new InvalidationManager(
            service: $service,
            strategy: $invalidationConfig['strategy'] ?? 'sync',
            versioned: $invalidationConfig['versioned'] ?? false,
            queue: $invalidationConfig['queue'] ?? 'default',
        );
    }

    protected static function touchRedisModelCacheParents(Model $model): void
    {
        $touches = static::resolveRedisModelCacheTouches();

        foreach ($touches as $relation) {
            $parent = $model->{$relation};

            if ($parent === null) {
                continue;
            }

            if ($parent instanceof Model) {
                static::storeParentCache($parent);
            } elseif ($parent instanceof Collection) {
                foreach ($parent as $p) {
                    if ($p instanceof Model) {
                        static::storeParentCache($p);
                    }
                }
            }
        }
    }

    protected static function storeParentCache(Model $parent): void
    {
        if (in_array(HasRedisModelCache::class, class_uses_recursive($parent::class), true)) {
            $parentService = static::resolveRedisModelCacheServiceFor($parent::class);
            $parentService->store($parent);
        }
    }

    protected static function resolveRedisModelCacheServiceFor(string $modelClass): RedisModelService
    {
        $config = method_exists($modelClass, 'redisModelCacheConfig')
            ? $modelClass::redisModelCacheConfig()
            : [];

        $params = [
            'model_class' => $modelClass,
            'indexes' => $config['indexes'] ?? [],
            'sorted' => $config['sorted'] ?? [],
            'custom_indexes' => $config['custom_indexes'] ?? [],
            'ttl' => $config['ttl'] ?? null,
            'connection' => $config['connection'] ?? null,
        ];

        return app(RedisModelService::class, $params);
    }

    /**
     * @return array<int, string>
     */
    protected static function resolveRedisModelCacheTouches(): array
    {
        if (method_exists(static::class, 'redisModelCacheTouches')) {
            return static::redisModelCacheTouches();
        }

        if (property_exists(static::class, 'redisModelCacheTouches')) {
            return static::$redisModelCacheTouches;
        }

        return [];
    }
}
