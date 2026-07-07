<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Sm_mE\RedisModelCache\Contracts\HashCacheService;
use Sm_mE\RedisModelCache\Contracts\ModelCacheService;

if (! function_exists('redisHelper')) {
    function redisHelper(?int $ttl = null): HashCacheService
    {
        return app(HashCacheService::class, compact('ttl'));
    }
}

if (! function_exists('redisModelHelper')) {
    /**
     * @param  array<int, string>  $indexes
     * @param  array<int, string>  $sorted
     * @param  array<string, array<int, string>>  $custom_indexes
     * @return ModelCacheService<int, Model>
     */
    function redisModelHelper(
        string $model_class,
        array $indexes = [],
        array $sorted = [],
        array $custom_indexes = [],
        ?int $ttl = null
    ): ModelCacheService {
        return app(ModelCacheService::class, compact('model_class', 'indexes', 'sorted', 'custom_indexes', 'ttl'));
    }
}

if (! function_exists('formatBytes')) {
    function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
