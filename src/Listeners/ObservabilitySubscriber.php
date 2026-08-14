<?php

declare(strict_types=1);

namespace SmmE\RedisModelCache\Listeners;

use SmmE\RedisModelCache\Events\CacheHit;
use SmmE\RedisModelCache\Events\CacheMiss;
use SmmE\RedisModelCache\Events\CacheOperationFailed;
use SmmE\RedisModelCache\Events\CacheWrite;
use SmmE\RedisModelCache\Events\ModelCacheInvalidated;
use SmmE\RedisModelCache\Events\QueryExecuted;
use SmmE\RedisModelCache\Events\RedisConnectionFailed;
use SmmE\RedisModelCache\Support\Observability;

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
