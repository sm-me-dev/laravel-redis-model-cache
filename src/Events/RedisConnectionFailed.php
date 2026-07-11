<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Events;

use Illuminate\Foundation\Events\Dispatchable;

class RedisConnectionFailed
{
    use Dispatchable;

    /**
     * @param  string  $operation  The Redis operation that failed
     * @param  string  $message  The exception message
     * @param  array<int, string>  $trace  Stack trace lines
     */
    public function __construct(
        public readonly string $operation,
        public readonly string $message,
        public readonly array $trace = [],
    ) {}
}
