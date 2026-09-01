<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Invalidation\Strategies;

use SMDev\RedisModelCache\Invalidation\Contracts\InvalidationStrategy;
use SMDev\RedisModelCache\Invalidation\InvalidationContext;
use SMDev\RedisModelCache\Jobs\InvalidateModelCacheJob;

final class AsyncStrategy implements InvalidationStrategy
{
    public function __construct(
        private readonly string $queue,
    ) {}

    public function invalidate(InvalidationContext $context): void
    {
        InvalidateModelCacheJob::dispatch($context)->onQueue($this->queue);
    }
}
