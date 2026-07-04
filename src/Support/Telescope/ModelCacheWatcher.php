<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Support\Telescope;

use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Sm_mE\RedisModelCache\Events\CacheHit;
use Sm_mE\RedisModelCache\Events\CacheMiss;
use Sm_mE\RedisModelCache\Events\QueryExecuted;

class ModelCacheWatcher
{
    /**
     * Handle the event and record it in Telescope.
     */
    public function record(CacheHit|CacheMiss|QueryExecuted $event): void
    {
        match (true) {
            $event instanceof CacheHit => $this->recordHit($event),
            $event instanceof CacheMiss => $this->recordMiss($event),
            $event instanceof QueryExecuted => $this->recordQuery($event),
        };
    }

    protected function recordHit(CacheHit $event): void
    {
        Telescope::recordCache(IncomingEntry::make([
            'type' => 'cache_hit',
            'model' => $event->modelClass,
            'query' => json_encode($event->query),
            'result_count' => $event->resultCount,
            'execution_time_ms' => $event->executionTime,
        ]));
    }

    protected function recordMiss(CacheMiss $event): void
    {
        Telescope::recordCache(IncomingEntry::make([
            'type' => 'cache_miss',
            'model' => $event->modelClass,
            'query' => json_encode($event->query),
            'stampede' => $event->stampedeProtectionUsed,
            'execution_time_ms' => $event->executionTime,
        ]));
    }

    protected function recordQuery(QueryExecuted $event): void
    {
        Telescope::recordCache(IncomingEntry::make([
            'type' => 'cache_query',
            'model' => $event->modelClass,
            'operation' => $event->operation,
            'parameters' => json_encode($event->parameters),
            'commands' => $event->commandCount,
            'execution_time_ms' => $event->executionTime,
            'result_count' => $event->resultCount,
        ]));
    }
}
