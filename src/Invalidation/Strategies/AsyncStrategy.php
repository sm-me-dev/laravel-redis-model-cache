<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Invalidation\Strategies;

use Sm_mE\RedisModelCache\Invalidation\Contracts\InvalidationStrategy;
use Sm_mE\RedisModelCache\Invalidation\InvalidationContext;
use Sm_mE\RedisModelCache\Jobs\InvalidateModelCacheJob;

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
