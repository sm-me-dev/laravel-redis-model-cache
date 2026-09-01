<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Attributes;

use Attribute;

/**
 * Marks this property as a cache index for equality lookups.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class CacheIndex {}
