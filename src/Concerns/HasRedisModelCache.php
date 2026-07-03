<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Sm_mE\RedisModelCache\RedisModelService;

trait HasRedisModelCache
{
    protected static array $redisModelCacheProcessing = [];

    public static function bootHasRedisModelCache(): void
    {
        static::saved(function (Model $model) {
            static::processRedisModelCacheSaved($model);
        });

        static::restored(function (Model $model) {
            static::processRedisModelCacheSaved($model);
        });

        static::deleted(function (Model $model) {
            if (static::isRedisModelCacheProcessing($model)) {
                return;
            }

            static::markRedisModelCacheProcessing($model);

            try {
                static::resolveRedisModelCacheService()->delete($model->getKey());
                static::touchRedisModelCacheParents($model);
            } finally {
                static::unmarkRedisModelCacheProcessing($model);
            }
        });
    }

    protected static function processRedisModelCacheSaved(Model $model): void
    {
        if (static::isRedisModelCacheProcessing($model)) {
            return;
        }

        static::markRedisModelCacheProcessing($model);

        try {
            static::resolveRedisModelCacheService()->storeModel($model);
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
        static::$redisModelCacheProcessing[static::class][] = $model->getKey();
    }

    protected static function unmarkRedisModelCacheProcessing(Model $model): void
    {
        $key = array_search(
            $model->getKey(),
            static::$redisModelCacheProcessing[static::class] ?? [],
            true
        );

        if ($key !== false) {
            unset(static::$redisModelCacheProcessing[static::class][$key]);
            static::$redisModelCacheProcessing[static::class] = array_values(
                static::$redisModelCacheProcessing[static::class]
            );
        }
    }

    protected static function resolveRedisModelCacheService(): RedisModelService
    {
        $modelClass = static::class;

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
            $parentService->storeModel($parent);
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
