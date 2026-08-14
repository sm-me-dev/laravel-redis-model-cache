<?php

declare(strict_types=1);

namespace SmmE\RedisModelCache\Invalidation\Contracts;

use SmmE\RedisModelCache\Invalidation\InvalidationContext;

interface InvalidationStrategy
{
    public function invalidate(InvalidationContext $context): void;
}
