<?php

declare(strict_types=1);

namespace SmmE\RedisModelCache\Support\Pulse;

use SmmE\RedisModelCache\Events\CacheHit;
use SmmE\RedisModelCache\Events\CacheMiss;
use SmmE\RedisModelCache\Support\Observability;

/**
 * Record cache metrics into the Observability collector from dispatched events.
 *
 * Register this class as an event subscriber in your AppServiceProvider:
 *
 * <code>
 * Event::subscribe(\SmmE\RedisModelCache\Support\Pulse\CacheMetricsSubscriber::class);
 * </code>
 */
class CacheMetricsSubscriber
{
    public function __construct(
        protected Observability $observability,
    ) {}

    public function handleCacheHit(CacheHit $event): void
    {
        $this->observability->recordHit();
    }

    public function handleCacheMiss(CacheMiss $event): void
    {
        $this->observability->recordMiss();
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @return array<class-string, list<string>>
     */
    public function subscribe(): array
    {
        return [
            CacheHit::class => ['handleCacheHit'],
            CacheMiss::class => ['handleCacheMiss'],
        ];
    }
}
