<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache;

use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;

class RedisBaseService
{
    public mixed $redis;

    protected ?int $ttl = null;

    protected string $redisPrefix;

    public function __construct(
        protected RedisConnectionResolver $connectionResolver,
        ?int $ttl = null
    ) {
        $this->ttl = $ttl;
        $this->redis = $connectionResolver->resolve();
        $this->redisPrefix = $connectionResolver->getPrefix();
    }

    protected function applyTTL(string $key): void
    {
        if (! $this->ttl) {
            return;
        }

        $ttl = $this->redis->ttl($key);

        if ($ttl === -1) {
            $this->redis->expire($key, $this->ttl);
        }
    }

    /**
     * @param  array<int, string>  $keys
     */
    protected function applyTTLToKeys(array $keys): void
    {
        if (! $this->ttl) {
            return;
        }

        foreach ($keys as $key) {
            if ($key !== '') {
                $this->applyTTL($key);
            }
        }
    }

    protected function serializeResult(mixed $result): string
    {
        if (is_string($result)) {
            return $result;
        }

        return (string) json_encode($result, JSON_THROW_ON_ERROR);
    }

    protected function deserializeResult(string $json): mixed
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
