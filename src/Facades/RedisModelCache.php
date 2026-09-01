<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Facades;

use Illuminate\Support\Facades\Facade;
use SMDev\RedisModelCache\Support\CacheManager;
use SMDev\RedisModelCache\Support\CacheMetrics;
use SMDev\RedisModelCache\Support\ExplainResult;

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
