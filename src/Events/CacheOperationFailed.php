<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CacheOperationFailed
{
    use Dispatchable;

    /**
     * @param  string  $operation  The cache operation that failed
     * @param  string  $message  The exception message
     * @param  mixed  $fallbackResult  The value returned by the fallback callback
     * @param  string  $strategy  The strategy used ('log' or 'fallback')
     */
    public function __construct(
        public readonly string $operation,
        public readonly string $message,
        public readonly mixed $fallbackResult = null,
        public readonly string $strategy = 'log',
    ) {}
}
