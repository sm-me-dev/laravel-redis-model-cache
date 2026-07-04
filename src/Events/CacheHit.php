<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CacheHit
{
    use Dispatchable;

    /**
     * @param  string  $modelClass  The fully qualified model class name
     * @param  array<string, mixed>  $query  The query conditions used
     * @param  int  $resultCount  Number of models returned
     * @param  float  $executionTime  Time taken in milliseconds
     */
    public function __construct(
        public readonly string $modelClass,
        public readonly array $query,
        public readonly int $resultCount,
        public readonly float $executionTime,
    ) {}
}
