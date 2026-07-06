<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Listeners;

use Sm_mE\RedisModelCache\Events\CacheHit;
use Sm_mE\RedisModelCache\Events\CacheMiss;
use Sm_mE\RedisModelCache\Events\QueryExecuted;
use Sm_mE\RedisModelCache\Support\Observability;

class ObservabilitySubscriber
{
    public function __construct(
        private readonly Observability $observability,
    ) {}

    public function handleCacheHit(CacheHit $event): void
    {
        $this->observability->recordHit();
        $this->observability->recordLatency($event->executionTime);
    }

    public function handleCacheMiss(CacheMiss $event): void
    {
        $this->observability->recordMiss();
        $this->observability->recordLatency($event->executionTime);
    }

    public function handleQueryExecuted(QueryExecuted $event): void
    {
        if ($event->operation === 'rememberAll' || $event->operation === 'storeMany') {
            $this->observability->recordPipelineSize($event->commandCount);
        }
    }

    /**
     * @return array<string, string>
     */
    public function subscribe(): array
    {
        return [
            CacheHit::class => 'handleCacheHit',
            CacheMiss::class => 'handleCacheMiss',
            QueryExecuted::class => 'handleQueryExecuted',
        ];
    }
}
