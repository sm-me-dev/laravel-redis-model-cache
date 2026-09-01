<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Attributes;

use Attribute;

/**
 * Declares the TTL, in seconds, for a model's cache entries.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class CacheTtl
{
    public function __construct(public readonly int $seconds) {}
}
