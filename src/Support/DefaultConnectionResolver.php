<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Support;

use Illuminate\Support\Facades\Redis;
use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;

class DefaultConnectionResolver implements RedisConnectionResolver
{
    protected mixed $client;

    protected string $prefix;

    public function __construct(?string $connection = null)
    {
        $connection ??= (string) config('redis-model-cache.connection', 'cache');

        $this->client = Redis::connection($connection)->client();
        $this->prefix = (string) config('database.redis.options.prefix', '');
    }

    public function resolve(): mixed
    {
        return $this->client;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }
}
