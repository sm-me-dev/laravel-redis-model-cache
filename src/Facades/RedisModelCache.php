<?php

declare(strict_types=1);

namespace SmmE\RedisModelCache\Facades;

use Illuminate\Support\Facades\Facade;
use SmmE\RedisModelCache\Support\CacheManager;
use SmmE\RedisModelCache\Support\CacheMetrics;
use SmmE\RedisModelCache\Support\ExplainResult;

/**
 * @method static CacheMetrics metrics()
 * @method static ExplainResult explain(string $modelClass, array<string, mixed>|\Closure $query)
 * @method static mixed resolve()
 * @method static string getPrefix()
 *
 * @see CacheManager
 */
class RedisModelCache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CacheManager::class;
    }
}
