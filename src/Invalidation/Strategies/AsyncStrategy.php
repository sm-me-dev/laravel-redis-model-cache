<?php

declare(strict_types=1);

namespace SmMe\RedisModelCache\Invalidation\Strategies;

use SmMe\RedisModelCache\Invalidation\Contracts\InvalidationStrategy;
use SmMe\RedisModelCache\Invalidation\InvalidationContext;
use SmMe\RedisModelCache\Jobs\InvalidateModelCacheJob;

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
