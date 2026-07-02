<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Facades;

use Illuminate\Support\Facades\Facade;
use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;

/**
 * @method static mixed resolve()
 * @method static string getPrefix()
 *
 * @see RedisConnectionResolver
 */
class RedisModelCache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RedisConnectionResolver::class;
    }
}
