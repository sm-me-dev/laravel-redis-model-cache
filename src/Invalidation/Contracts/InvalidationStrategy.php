<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Invalidation\Contracts;

use Sm_mE\RedisModelCache\Invalidation\InvalidationContext;

interface InvalidationStrategy
{
    public function invalidate(InvalidationContext $context): void;
}
