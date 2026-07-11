# Observability & Debugging

## Events

All events use Laravel's standard `Dispatchable` trait and can be consumed via `Event::listen()` or `Event::subscribe()`.

| Event | When | Properties |
|-------|------|------------|
| `CacheHit` | Cache returns results for a query | `modelClass`, `query`, `resultCount`, `executionTime` |
| `CacheMiss` | Cache must be rebuilt from DB | `modelClass`, `query`, `stampedeProtectionUsed`, `executionTime` |
| `CacheWrite` | Models stored or deleted in Redis | `modelClass`, `operation`, `modelIds`, `executionTime`, `modelCount` |
| `QueryExecuted` | Every Redis query operation | `modelClass`, `operation`, `parameters`, `commandCount`, `executionTime`, `resultCount` |
| `ModelCacheInvalidated` | Model cache invalidated on save/delete | `modelClass`, `modelId`, `event`, `timestamp` |
| `RedisConnectionFailed` | Redis operation fails with `log` strategy | `operation`, `message`, `trace` |
| `CacheOperationFailed` | Redis operation fails with `fallback` strategy | `operation`, `message`, `fallbackResult`, `strategy` |

```php
use Sm_mE\RedisModelCache\Events\CacheHit;

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

The `Observability` singleton provides in-memory ring-buffered metrics.

```php
use Sm_mE\RedisModelCache\Support\Observability;

$metrics = app(Observability::class);
$report = $metrics->snapshot();
// {
//     'hits' => 150,
//     'misses' => 12,
//     'hit_rate' => 92.59,
//     'writes' => 45,
//     'invalidations' => 8,
//     'failures' => 2,
//     'latency' => ['p50' => 1.23, 'p95' => 4.56, 'p99' => 8.90, ...],
//     'pipeline_size' => ['min' => 1, 'max' => 500, 'average' => 42.3, ...],
//     'stale_cleanup' => ['count' => 5, 'keys_removed' => 23],
//     'lock_contention' => 0,
// }
```

### Available Metrics

| Metric | Method | Description |
|--------|--------|-------------|
| Cache hits | `hits()`, `hitRate()` | Successful cache retrievals / hit percentage |
| Cache misses | `misses()`, `missRate()` | Missed cache / miss percentage |
| Writes | `writeCount()` | `storeMany` and `delete` operations |
| Invalidations | `invalidationCount()` | Model cache invalidations on save/delete |
| Failures | `failureCount()` | Redis connection or operation failures |
| Latency | `latencyPercentile(95)`, `averageLatency()`, `minLatency()`, `maxLatency()` | Operation duration in ms (ring buffer, 1000 samples) |
| Pipeline size | `pipelineSizeDistribution()` | Batch size distribution for `storeMany` and `rememberAll` |
| Stale cleanup | `staleCleanupCount()`, `staleCleanupKeysRemoved()` | SWR stale index cleanup operations |
| Lock contention | `lockContentionCount()` | Stampede protection lock contention events |

## Octane / Long-Running Worker Safety (v2.2)

In v2.2, all internal sample arrays use bounded ring buffers to prevent memory growth
in long-lived worker processes:

| Sample Store | Type | Capacity | Overflow Behavior |
|-------------|------|----------|-------------------|
| `$latencySamples` | Ring buffer | 1000 | Overwrites oldest, modulo index |
| `$pipelineSizes` | Ring buffer | 1000 | Overwrites oldest, modulo index |

Counters (hits, misses, writes, invalidations, failures) use safe normalization:
when any counter reaches `PHP_INT_MAX >> 2`, all counters are halved to preserve
ratios while preventing overflow.

**Lifecycle reset**: `Observability::reset()` is called automatically on Octane
`WorkerTickStarting` events. For FPM environments, the singleton spans request
lifetime only — no accumulation between requests.

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

## Telescope Integration

Register the watcher in your `AppServiceProvider`:

```php
use Sm_mE\RedisModelCache\Support\Telescope\ModelCacheWatcher;

Event::listen(
    ['Sm_mE\RedisModelCache\Events\CacheHit',
     'Sm_mE\RedisModelCache\Events\CacheMiss',
     'Sm_mE\RedisModelCache\Events\CacheWrite',
     'Sm_mE\RedisModelCache\Events\QueryExecuted',
     'Sm_mE\RedisModelCache\Events\ModelCacheInvalidated'],
    ModelCacheWatcher::class
);
```

### Custom Telescope Watcher

Create a dedicated watcher to display cache metrics in the Telescope sidebar:

```php
namespace App\Telescope;

use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;

class CacheWatcher
{
    public function register(): void
    {
        Telescope::filter(function (IncomingEntry $entry) {
            if ($entry->type === 'cache') {
                return true;
            }

            return $entry->isReportable();
        });
    }
}
```

## Pulse Integration

Subscribe cache metrics for Pulse recording:

```php
use Sm_mE\RedisModelCache\Support\Pulse\CacheMetricsSubscriber;

Event::subscribe(CacheMetricsSubscriber::class);
```

### Custom Pulse Card

Create a Pulse card to visualize cache hit rate, latency percentiles, and failure
counts in your Pulse dashboard:

```php
namespace App\Pulse;

use Laravel\Pulse\Pulse;
use Livewire\Component;

class CacheMetricsCard extends Component
{
    public function render(Pulse $pulse)
    {
        $metrics = app(\Sm_mE\RedisModelCache\Support\Observability::class);

        return view('livewire.cache-metrics', [
            'hitRate' => $metrics->hitRate(),
            'p95' => $metrics->latencyPercentile(95),
            'writes' => $metrics->writeCount(),
            'failures' => $metrics->failureCount(),
        ]);
    }
}
```

Access raw metrics via `app(\Sm_mE\RedisModelCache\Support\Observability::class)`.

## Security Guardrails

- **No sensitive data in events**: Event payloads contain model class names, operation
  types, and timing data — never Redis credentials, connection strings, or cached
  model attribute values.
- **Execution times only**: `CacheHit`, `CacheMiss`, `CacheWrite`, and `QueryExecuted`
  include `executionTime` (float ms) but not the actual cached data payloads.
- **Failures are context-only**: `RedisConnectionFailed` and `CacheOperationFailed`
  include operation names and exception messages — never stack traces containing
  credentials or the failed Redis command arguments.
- **Disable entirely**: Set `'observability' => ['enabled' => false]` in the config.
  The service singleton is still resolved but all dispatch guards check the config
  flag first, adding zero overhead when disabled.
- **Per-operation opt-out**: Use `$service->withoutMetrics()->where([...])` to skip
  event dispatch for a single operation.
