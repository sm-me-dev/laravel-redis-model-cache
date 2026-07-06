<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Invalidation;

readonly class InvalidationContext
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $original
     */
    public function __construct(
        public string $modelClass,
        public int|string $modelId,
        public string $event,
        public array $attributes,
        public array $original,
        public float $timestamp,
    ) {}
}
