<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Support;

class ResolvedIndex
{
    /**
     * @param  string  $strategy  Redis strategy: 'single_key_lookup', 'intersection', 'union', 'direct_hash', 'range'
     * @param  array<int, string>  $keys  Redis keys to query
     * @param  string  $command  Redis command: 'SMEMBERS', 'SINTER', 'SUNION', 'SCARD', 'EXISTS', 'ZRANGEBYSCORE', 'HGET'
     * @param  array<string, mixed>  $metadata  Extra metadata (field count, estimated cardinality hint, etc.)
     */
    public function __construct(
        public readonly string $strategy,
        public readonly array $keys,
        public readonly string $command,
        public readonly array $metadata = [],
    ) {}

    public function isSingleKey(): bool
    {
        return count($this->keys) === 1;
    }
}
