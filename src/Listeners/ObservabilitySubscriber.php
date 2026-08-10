<?php

declare(strict_types=1);

namespace SmMe\RedisModelCache\Listeners;

use SmMe\RedisModelCache\Events\CacheHit;
use SmMe\RedisModelCache\Events\CacheMiss;
use SmMe\RedisModelCache\Events\CacheOperationFailed;
use SmMe\RedisModelCache\Events\CacheWrite;
use SmMe\RedisModelCache\Events\ModelCacheInvalidated;
use SmMe\RedisModelCache\Events\QueryExecuted;
use SmMe\RedisModelCache\Events\RedisConnectionFailed;
use SmMe\RedisModelCache\Support\Observability;

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
