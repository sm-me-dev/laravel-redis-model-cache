<?php

declare(strict_types=1);

namespace SmMe\RedisModelCache\Facades;

use Illuminate\Support\Facades\Facade;
use SmMe\RedisModelCache\Support\CacheManager;
use SmMe\RedisModelCache\Support\CacheMetrics;
use SmMe\RedisModelCache\Support\ExplainResult;

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
