<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CacheMiss
{
    use Dispatchable;

    /**
     * @param  string  $modelClass  The fully qualified model class name
     * @param  array<string, mixed>  $query  The query conditions used
     * @param  bool  $stampedeProtectionUsed  Whether stampede lock was acquired
     * @param  float  $executionTime  Time taken in milliseconds
     */
    public function __construct(
        public readonly string $modelClass,
        public readonly array $query,
        public readonly bool $stampedeProtectionUsed,
        public readonly float $executionTime,
    ) {}
}
