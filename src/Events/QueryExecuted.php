<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Events;

use Illuminate\Foundation\Events\Dispatchable;

class QueryExecuted
{
    use Dispatchable;

    /**
     * @param  string  $modelClass  The fully qualified model class name
     * @param  string  $operation  The operation type (where, remember, rememberAll, etc.)
     * @param  array<string, mixed>  $parameters  Query parameters
     * @param  int  $commandCount  Number of Redis commands executed
     * @param  float  $executionTime  Time taken in milliseconds
     * @param  int  $resultCount  Number of results returned
     */
    public function __construct(
        public readonly string $modelClass,
        public readonly string $operation,
        public readonly array $parameters,
        public readonly int $commandCount,
        public readonly float $executionTime,
        public readonly int $resultCount,
    ) {}
}
