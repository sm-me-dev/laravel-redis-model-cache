<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Invalidation\Contracts;

use SMDev\RedisModelCache\Invalidation\InvalidationContext;

interface InvalidationStrategy
{
    public function invalidate(InvalidationContext $context): void;
}
