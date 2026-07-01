<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Facades;

use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;
use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed resolve()
 * @method static string getPrefix()
 *
 * @see \Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver
 */
class RedisModelCache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RedisConnectionResolver::class;
    }
}
