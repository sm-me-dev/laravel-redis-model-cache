<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Support\Pulse;

use Illuminate\Database\Eloquent\Model;
use SMDev\RedisModelCache\Contracts\RedisConnectionResolver;
use SMDev\RedisModelCache\Events\CacheHit;
use SMDev\RedisModelCache\Events\CacheMiss;
use SMDev\RedisModelCache\Events\QueryExecuted;
use SMDev\RedisModelCache\Support\RedisKeyBuilder;

/**
 * Collects per-model cache metrics for optional Laravel Pulse integration.
 *
 * The recorder has no hard dependency on Pulse. When a compatible recorder
 * instance is supplied, snapshots are forwarded through its record() method.
 */
final class ModelCacheRecorder
{
    /** @var array<string, array{hits: int, misses: int, query_ms: float, queries: int}> */
    private array $metrics = [];

    public function __construct(
        private readonly mixed $pulse = null,
        private readonly ?RedisConnectionResolver $connectionResolver = null,
    ) {}

    public function recordHit(CacheHit $event): void
    {
        $metric = $this->metricFor($event->modelClass);
        $metric['hits']++;
        $this->metrics[$event->modelClass] = $metric;
        $this->flush($event->modelClass);
    }

    public function recordMiss(CacheMiss $event): void
    {
        $metric = $this->metricFor($event->modelClass);
        $metric['misses']++;
        $this->metrics[$event->modelClass] = $metric;
        $this->flush($event->modelClass);
    }

    public function recordQuery(QueryExecuted $event): void
    {
        $metric = $this->metricFor($event->modelClass);
        $metric['query_ms'] += $event->executionTime;
        $metric['queries']++;
        $this->metrics[$event->modelClass] = $metric;
        $this->flush($event->modelClass);
    }

    /**
     * @return array{hits: int, misses: int, query_ms: float, queries: int}
     */
    private function metricFor(string $modelClass): array
    {
        return $this->metrics[$modelClass] ?? [
            'hits' => 0,
            'misses' => 0,
            'query_ms' => 0.0,
            'queries' => 0,
        ];
    }

    /**
     * @return array<string, array{hits: int, misses: int, query_ms: float, queries: int}>
     */
    public function metrics(): array
    {
        return $this->metrics;
    }

    /**
     * @return array<class-string, list<string>>
     */
    public function subscribe(): array
    {
        return [
            CacheHit::class => ['recordHit'],
            CacheMiss::class => ['recordMiss'],
            QueryExecuted::class => ['recordQuery'],
        ];
    }

    private function flush(string $modelClass): void
    {
        if (! is_object($this->pulse)
            || (! method_exists($this->pulse, 'record') && ! is_callable([$this->pulse, 'record']))) {
            return;
        }

        $metric = $this->metrics[$modelClass];
        $total = $metric['hits'] + $metric['misses'];
        $cachedCount = $this->cachedCount($modelClass);

        $this->pulse->record('redis_model_cache', [
            'model' => $modelClass,
            'hits' => $metric['hits'],
            'misses' => $metric['misses'],
            'hit_rate' => $total === 0 ? 0.0 : round(($metric['hits'] / $total) * 100, 2),
            'avg_query_ms' => $metric['queries'] === 0 ? 0.0 : round($metric['query_ms'] / $metric['queries'], 4),
            'cached_count' => $cachedCount,
        ]);
    }

    private function cachedCount(string $modelClass): int
    {
        if ($this->connectionResolver === null
            || ! class_exists($modelClass)
            || ! is_subclass_of($modelClass, Model::class)) {
            return 0;
        }

        try {
            /** @var Model $model */
            $model = new $modelClass;
            $key = RedisKeyBuilder::for($model->getTable())->hashKey();

            return (int) $this->connectionResolver->resolve()->hlen($key);
        } catch (\Throwable) {
            return 0;
        }
    }
}
