# Observability & Debugging

## Events

```php
use Sm_mE\RedisModelCache\Events\CacheHit;
use Sm_mE\RedisModelCache\Events\CacheMiss;
use Sm_mE\RedisModelCache\Events\QueryExecuted;

Event::listen(CacheHit::class, function (CacheHit $event) {
    Log::info('Cache hit', [
        'model'  => $event->modelClass,
        'query'  => $event->query,
        'results' => $event->resultCount,
        'time_ms' => $event->executionTime,
    ]);
});
```

Disable events per operation: `$cacheService->withoutMetrics()->where([...])`.

## Metrics Collector

```php
use Sm_mE\RedisModelCache\Support\Observability;

$metrics = app(Observability::class);
$report = $metrics->snapshot();
// {
//     'hits' => 150,
//     'misses' => 12,
//     'hit_rate' => 92.59,
//     'latency' => ['p50' => 1.23, 'p95' => 4.56, 'p99' => 8.90, ...],
//     'stale_cleanup' => ['count' => 5, 'keys_removed' => 23],
//     'lock_contention' => 0,
// }
```

Plus per-metric methods: `hitRate()`, `missRate()`, `averageLatency()`, `latencyPercentile(95)`, etc.

## Debug Mode

Log all Redis operations per service instance:

```php
$service->debug()->where(['role_id' => 1]);
```

## Key Inspection

View all Redis keys and data for a cached model:

```php
$info = $service->inspect(42);
```

Returns hash key, payload, TTL, index memberships, sorted scores, and metadata.

## Index Cardinality Report

Analyze all index set sizes:

```php
$report = $service->analyzeIndexes();
```

Returns total model count, TTL, and per-index cardinalities.

## Telescope Integration

Register the watcher in your `AppServiceProvider`:

```php
use Sm_mE\RedisModelCache\Support\Telescope\ModelCacheWatcher;

Event::listen(
    ['Sm_mE\RedisModelCache\Events\CacheHit',
     'Sm_mE\RedisModelCache\Events\CacheMiss',
     'Sm_mE\RedisModelCache\Events\QueryExecuted'],
    ModelCacheWatcher::class
);
```

## Pulse Integration

Subscribe cache metrics:

```php
use Sm_mE\RedisModelCache\Support\Pulse\CacheMetricsSubscriber;

Event::subscribe(CacheMetricsSubscriber::class);
```

Then access via `app(\Sm_mE\RedisModelCache\Support\Observability::class)`.
