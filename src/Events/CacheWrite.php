<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CacheWrite
{
    use Dispatchable;

    /**
     * @param  string  $modelClass  The fully qualified model class name
     * @param  string  $operation  The write operation (store, storeMany, delete)
     * @param  array<int, int|string>  $modelIds  The affected model primary keys
     * @param  float  $executionTime  Time taken in milliseconds
     * @param  int  $modelCount  Number of models written
     */
    public function __construct(
        public readonly string $modelClass,
        public readonly string $operation,
        public readonly array $modelIds = [],
        public readonly float $executionTime = 0.0,
        public readonly int $modelCount = 0,
    ) {}
}
