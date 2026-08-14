<?php

declare(strict_types=1);

namespace SmmE\RedisModelCache\Invalidation\Strategies;

use SmmE\RedisModelCache\Invalidation\Contracts\InvalidationStrategy;
use SmmE\RedisModelCache\Invalidation\InvalidationContext;
use SmmE\RedisModelCache\Jobs\InvalidateModelCacheJob;

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
