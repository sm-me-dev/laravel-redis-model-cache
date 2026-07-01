<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache;

use Illuminate\Support\Arr;
use Sm_mE\RedisModelCache\Contracts\HashCacheService;
use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;

class RedisHelperService extends RedisBaseService implements HashCacheService
{
    public function __construct(
        RedisConnectionResolver $connectionResolver,
        ?int $ttl = null
    ) {
        parent::__construct($connectionResolver, $ttl);
    }

    public function rememberSet(string $hashset, string $key, callable $callback, bool $refresh = false, bool $serialize = true): mixed
    {
        if ($refresh || ! $this->redis->hExists($hashset, $key)) {
            $result = $callback();

            if ($serialize) {
                $result = $this->serializeResult($result);
            }

            $this->redis->hset($hashset, $key, $result);
            $this->applyTTL($hashset);
        } else {
            $result = $this->redis->hget($hashset, $key);
        }

        return $serialize ? $this->deserializeResult((string) $result) : $result;
    }

    public function getSet(string $hashset): array
    {
        return Arr::map(
            $this->redis->hGetAll($hashset),
            fn (mixed $value): mixed => $this->deserializeResult((string) $value)
        );
    }
}
