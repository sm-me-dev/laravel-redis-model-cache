<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Contracts;

interface HashCacheService
{
    public function rememberSet(string $hashset, string $key, callable $callback, bool $refresh = false, bool $serialize = true): mixed;

    /**
     * @return array<string, mixed>
     */
    public function getSet(string $hashset): array;
}
