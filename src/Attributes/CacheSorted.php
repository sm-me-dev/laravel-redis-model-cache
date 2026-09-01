<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Attributes;

use Attribute;

/**
 * Marks this property as a sorted cache index.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class CacheSorted {}
