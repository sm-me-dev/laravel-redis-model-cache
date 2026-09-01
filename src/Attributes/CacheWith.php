<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Attributes;

use Attribute;

/**
 * Declares relations that should be eager-loaded before caching a model.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class CacheWith
{
    /**
     * @param  list<string>  $relations
     */
    public function __construct(public readonly array $relations) {}
}
