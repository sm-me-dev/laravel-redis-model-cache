<?php

declare(strict_types=1);

use Sm_mE\RedisModelCache\Contracts\HashCacheService;
use Sm_mE\RedisModelCache\Contracts\ModelCacheService;

if (! function_exists('redisHelper')) {
    function redisHelper(?int $ttl = null): HashCacheService
    {
        return app(HashCacheService::class, compact('ttl'));
    }
}

if (! function_exists('redisModelHelper')) {
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
