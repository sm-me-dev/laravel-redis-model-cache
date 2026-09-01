<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Listeners;

use SMDev\RedisModelCache\Events\CacheHit;
use SMDev\RedisModelCache\Events\CacheMiss;
use SMDev\RedisModelCache\Events\CacheOperationFailed;
use SMDev\RedisModelCache\Events\CacheWrite;
use SMDev\RedisModelCache\Events\ModelCacheInvalidated;
use SMDev\RedisModelCache\Events\QueryExecuted;
use SMDev\RedisModelCache\Events\RedisConnectionFailed;
use SMDev\RedisModelCache\Support\Observability;

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

    public function handleCacheWrite(CacheWrite $event): void
    {
        $this->observability->recordWrite();
    }

    public function handleModelCacheInvalidated(ModelCacheInvalidated $event): void
    {
        $this->observability->recordInvalidation();
    }

    public function handleRedisConnectionFailed(RedisConnectionFailed $event): void
    {
        $this->observability->recordFailure();
    }

    public function handleCacheOperationFailed(CacheOperationFailed $event): void
    {
        $this->observability->recordFailure();
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
            CacheWrite::class => 'handleCacheWrite',
            ModelCacheInvalidated::class => 'handleModelCacheInvalidated',
            RedisConnectionFailed::class => 'handleRedisConnectionFailed',
            CacheOperationFailed::class => 'handleCacheOperationFailed',
        ];
    }
}
