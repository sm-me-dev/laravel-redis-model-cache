<?php

declare(strict_types=1);

namespace SmMe\RedisModelCache\Invalidation\Contracts;

use SmMe\RedisModelCache\Invalidation\InvalidationContext;

interface InvalidationStrategy
{
    public function invalidate(InvalidationContext $context): void;
}
