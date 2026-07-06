<p align="center">
    <h1 align="center">Laravel Redis Model Cache</h1>
    <p align="center">Deterministic Redis model caching for Laravel Eloquent — hash-based, index-aware, production-tested.</p>
</p>

<p align="center">
    <a href="https://packagist.org/packages/sm-me/laravel-redis-model-cache"><img src="https://img.shields.io/packagist/v/sm-me/laravel-redis-model-cache" alt="Latest Version"></a>
    <a href="https://packagist.org/packages/sm-me/laravel-redis-model-cache"><img src="https://img.shields.io/packagist/php-v/sm-me/laravel-redis-model-cache" alt="PHP Version"></a>
    <a href="https://packagist.org/packages/sm-me/laravel-redis-model-cache"><img src="https://img.shields.io/packagist/l/sm-me/laravel-redis-model-cache" alt="License"></a>
    <a href="https://codecov.io/gh/sm-me/laravel-redis-model-cache"><img src="https://codecov.io/gh/sm-me/laravel-redis-model-cache/branch/main/graph/badge.svg" alt="Code Coverage"></a>
    <a href="https://github.com/sm-me/laravel-redis-model-cache/actions"><img src="https://github.com/sm-me/laravel-redis-model-cache/actions/workflows/run-tests.yml/badge.svg" alt="Tests"></a>
    <a href="https://github.com/sm-me/laravel-redis-model-cache/actions"><img src="https://github.com/sm-me/laravel-redis-model-cache/actions/workflows/static-analysis.yml/badge.svg" alt="Static Analysis"></a>
</p>

---

**v2.2.0** | PHP ^8.4 | Laravel ^12.0

## Overview

This package replaces Eloquent's O(N) query-per-row pattern with O(1) Redis hash lookups. Every model is serialized once into a Redis hash, referenced by index sets for fast lookup, and invalidated deterministically via lifecycle hooks.

Unlike generic key-value caches, this package is **index-aware**: queries must use declared indexed fields, enabling SINTER/SMEMBERS lookups instead of HSCAN or KEYS scans. Full hash scans are blocked at the API level to prevent OOM.

### Key capabilities

- **Hash-based storage** — all model data (attributes + eager-loaded relations) in one hash per table
- **Index-driven queries** — Redis sets for equality lookups, sorted sets for range queries, SUNION for OR logic
- **Deterministic invalidation** — model lifecycle (saved/deleted/forceDeleted) triggers exact index cleanup via `HasRedisModelCache` trait
- **Stampede protection** — Redis `SET NX EX` lock with exponential-backoff polling, jitter, and fail-fast timeouts
- **Stale-while-revalidate** — serve stale data during TTL expiry while a queue job refreshes in background
- **Atomic Lua stores** — zero string parsing, mathematical offset indexing, discrete ARGV counts (v2.2)
- **Batch atomic writes** — `storeMany()` pipelines EVALSHA commands with explicit script priming (v2.2)
- **Incremental updates** — `updateAttribute`/`updateAttributes` modify a single field without full re-serialization
- **Compression** — gzip/zstd/lz4 with `min_size` threshold to skip CPU waste on small payloads
- **Partial hydration** — `selective()` / `pluck()` fetch only requested fields, 60-80% less memory
- **Multi-tenant isolation** — `{tenant:{id}:{table}}` prefix via `TenantResolverInterface`
- **Cluster-safe** — hash tags `{...}` keep all keys for a model on the same cluster node
- **Observability** — `CacheHit`/`CacheMiss`/`QueryExecuted` events, debug mode, inspect/analyzeIndexes tooling
- **Octane-ready** — bounded ring buffers, lifecycle state flushing, no static bleed between requests (v2.2)

## v2.2 Architecture: Production-Grade Concurrency & Memory Safety

### Lua Atomic Store: Zero String Parsing

The atomic store Lua script (`LUA_ATOMIC_STORE`) uses **mathematical offset indexing** instead of string parsing:

- **v2.1 and earlier**: `string.gmatch(ARGV[4], "%S+")` parsed space-separated count tokens, and `string.gmatch(ARGV[5], "[^,]+")` parsed comma-separated scores
- **v2.2**: Discrete `ARGV[4]` through `ARGV[7]` carry individual counts (stale SREM, new SADD, stale ZREM, new ZADD). Scores are passed as individual `ARGV[8..7+Q]` entries. The loop uses `tonumber(ARGV[7 + i])` — no string parsing, no memory allocation inside Lua

This eliminates Lua GC pressure under high-throughput store operations and removes ARGV marshalling ambiguity.

### Batch Atomicity: Pipelined EVALSHA

`storeMany()` now pipelines **EVALSHA commands** instead of individual Redis writes:

1. HMGET batch-reads old data (1 round trip)
2. `primeAtomicStoreScript()` loads the script into Redis via `SCRIPT LOAD` (explicit priming, no NOSCRIPT fallback within batch)
3. Pipeline queues EVALSHA per model — HSET + SREM + SADD + ZADD atomically in one server-side call per model
4. `executePipeline()` flushes all EVALSHA commands atomically

This guarantees that a batch write either fully succeeds or fully fails from the caller's perspective. No per-command partial failure.

### SWR Background Worker Pipeline

When stale-while-revalidate is enabled, `RevalidateCacheJob` dispatches to the configured Laravel queue:

```
Cache read → stale but within grace period → return stale data immediately
                                          → dispatch RevalidateCacheJob
                                          → worker executes callback
                                          → storeMany() repopulates cache
```

The revalidation job captures the full service configuration (indexes, sorted fields, connection, TTL) as serialized constructor parameters — no service container dependency at job execution time.

### Thundering Herd Elimination

`StampedeProtection::waitForLock()` uses exponential backoff with randomized jitter:

| Attempt | Base Sleep | Max with Jitter |
|---------|-----------|-----------------|
| 0 | — | Initial jitter 0–10ms |
| 1 | 100ms | 100–150ms |
| 2 | 200ms | 200–300ms |
| 3 | 400ms | 400–600ms |
| 4 | 800ms | 800–1200ms |
| 5+ | 1000ms (capped) | 1000–1500ms |

Key properties:
- **Fail-fast**: returns `false` when `$deadline` is exceeded — no indefinite blocking
- **De-synchronized**: initial `random_int()` jitter prevents all concurrent waiters from polling simultaneously
- **Worker-safe**: does not block FPM/Octane worker pools indefinitely; the caller decides the timeout budget

### Octane Memory Isolation

Long-lived workers (Octane, RoadRunner) cannot leak state between requests:

| Component | Mechanism | Scope |
|-----------|-----------|-------|
| `Observability::latencySamples[]` | Ring buffer, `MAX_LATENCY=1000`, modulo-based circular index | Singleton |
| `Observability::pipelineSizes[]` | Ring buffer, `MAX_PIPELINE=1000`, modulo-based circular index | Singleton |
| `HasRedisModelCache::$redisModelCacheProcessing[]` | `flushRedisModelCacheProcessing()` on `App::terminating` | Per-request static |
| `HasRedisModelCache::$redisModelCacheDeletedInCycle[]` | `flushRedisModelCacheProcessing()` on `App::terminating` | Per-request static |
| `Observability::reset()` | Called on `WorkerTickStarting` (Octane) | Between requests |

The `flattenRingBuffer()` method correctly linearises the ring buffer for percentile calculations regardless of partial fill or wrap-around.

### Production Redis Safety: No KEYS

All pattern-matching operations use cursor-based `SCAN` with a configurable batch size (`scan_count`, default 1000):

| Command | File | Before v2.2 | v2.2 |
|---------|------|-------------|------|
| `MonitorCacheCommand::showKeys()` | `src/Console/MonitorCacheCommand.php` | `$redis->keys()` | `$this->scanKeys()` |
| `MonitorCacheCommand::checkTTL()` | `src/Console/MonitorCacheCommand.php` | `$redis->keys()` | `$this->scanKeys()` |
| `MonitorCacheCommand::showMemory()` | `src/Console/MonitorCacheCommand.php` | `$redis->keys()` | `$this->scanKeys()` |
| `MonitorCacheCommand::clearCache()` | `src/Console/MonitorCacheCommand.php` | `$redis->keys()` | `$this->scanKeys()` |
| `clearAll()`, `clear()` | `src/RedisModelService.php` | — | `collectKeysByPattern()` (SCAN) |
| `analyzeIndexes()` | `src/RedisModelService.php` | — | `collectKeysByPattern()` (SCAN) |

The config validation layer explicitly rejects any `scan_strategy` other than `'scan'` — the `KEYS` command is unsupported at the codebase level.

## Table of Contents

| Document | Audience |
|----------|----------|
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | Engineers — data flow, key layout, Redis ops, design decisions |
| [`QUERY_LIMITATIONS.md`](QUERY_LIMITATIONS.md) | All queries — what works, what doesn't, why |
| [`INVALIDATION.md`](INVALIDATION.md) | Engineers — lifecycle hooks, versioning, edge cases, parent touches |
| [`docs/observability.md`](docs/observability.md) | Operators — events, metrics, debug, Telescope/Pulse |
| [`docs/benchmarks.md`](docs/benchmarks.md) | Decision-makers — throughput, latency, memory (up to 21k models/s) |

## Quick Start

```bash
composer require sm-me/laravel-redis-model-cache
php artisan vendor:publish --tag=redis-model-cache-config
```

### Manual service usage

```php
use Sm_mE\RedisModelCache\RedisModelService;

$cache = app(RedisModelService::class, [
    'model_class' => User::class,
    'indexes' => ['role_id', 'status'],
    'sorted' => ['created_at'],
    'ttl' => 3600,
]);

// Populate cache (hits DB, stores in Redis)
$users = $cache->rememberAll(
    callback: fn() => User::all(),
    where: ['status' => 'active'],
);

// Subsequent calls hit Redis only — zero DB queries
$activeUsers = $cache->where(['status' => 'active']);
```

### Eloquent trait (auto-sync)

```php
use Sm_mE\RedisModelCache\Concerns\HasRedisModelCache;

class User extends Model
{
    use HasRedisModelCache;

    protected static function redisModelCacheConfig(): array
    {
        return [
            'indexes' => ['role_id', 'status'],
            'sorted' => ['created_at'],
            'ttl' => 3600,
        ];
    }
}
```

The trait hooks into `saved`, `deleted`, and `forceDeleted` events to keep Redis in sync. Relations are automatically touched; stale index entries cleaned on attribute changes.

### Artisan commands

```bash
# Warm cache for a model
php artisan redis-model-cache:warmup "App\Models\User" --where=status=active --indexes=role_id,status

# Debug: inspect Redis state, metrics, config
php artisan redis-cache:debug
```

## Requirements

- PHP 8.4+
- Laravel 12+
- Redis (cluster or single-node)
- `ext-redis` (phpredis) or `predis/predis`

## Public API

### `RedisModelService`

The service is the core of the package. It is instantiated via the service container:

```php
$cache = app(RedisModelService::class, [
    'model_class' => User::class,
    'indexes' => ['role_id', 'status'],
    'sorted' => ['created_at'],
    'custom_indexes' => ['active_admins' => ['role_id' => 1, 'status' => 'active']],
    'ttl' => 3600,
    'connection' => 'redis',        // optional: override Redis connection
]);
```

| Query Method | Redis Ops | Notes |
|---|---|---|
| `where(['field' => 'value'])` | SMEMBERS or SINTER | Indexed fields only. Throws on unindexed fields. |
| `whereIn('field', ['a', 'b'])` | SUNION | Indexed fields only. |
| `whereBetween('field', $min, $max)` | ZRANGEBYSCORE | Sorted fields only. |
| `orWhere($where, $baseIds)` | SINTER + array_merge | Combine with a previous where result set. |
| `first($where)` | SMEMBERS/SINTER + HGET | Takes first matching ID only. |
| `count($where)` | SCARD or SINTER | Single index: O(1). Multi: O(N). |
| `exists($where)` | EXISTS or SINTER | Single index: O(1). Multi: O(N). |
| `find($id)` | HGET | Direct PK lookup, no index needed. |
| `selective($fields, $where)` | SINTER + HMGET | Returns arrays, not models. |
| `pluck($attributes, $where)` | SINTER + HMGET | Returns arrays, not models. |
| `sorted($field, $start, $end)` | ZREVRANGE | Sorted fields only. |
| `paginateSorted($field, $page, $perPage)` | ZREVRANGE | Offset calculated from page/perPage. |
| `custom($name)` | SMEMBERS | Custom index sets. |
| `customWhere($names)` | SINTER | Intersection of custom index sets. |
| `remember($callback, findBy:)` | — | Cache-through with optional index find. |
| `rememberAll($callback, where:)` | exists + store + where | Primary cache-through method. |
| `rememberIndex($field, $value, $callback)` | exists + SMEMBERS | Index-scoped cache-through. |
| `rememberCustom($name, $callback)` | exists + SMEMBERS | Custom-index scoped cache-through. |

| Mutation Method | Redis Ops | Notes |
|---|---|---|
| `store($model)` | EVALSHA (Lua atomic) or pipeline | Direct store with atomic Lua. Fallback to pipeline when Lua unavailable. |
| `storeMany($models)` | Pipeline(EVALSHA × N) with explicit script priming | Batch store, single HMGET for stale reads, EVALSHA per model. |
| `delete($id)` | HGET + HDEL + SREM × N + ZREM × N | Reads old data to clean up stale index entries. |
| `updateAttribute($id, $field, $value)` | HGET + pipeline(HSET + SREM/SADD) | Incremental, preserves relations. |
| `updateAttributes($id, $attrs)` | HGET + pipeline(HSET + SREM/SADD) | Batch incremental, preserves relations. |
| `bustVersion()` | HINCRBY | Increments version counter in meta. |
| `clear()` | SCAN + DEL | Clears hash + indexes + sorted + custom. |
| `clearAll()` | SCAN + DEL | All keys for this prefix. |

| Debug / Observability | Redis Ops | Notes |
|---|---|---|
| `inspect($id)` | HGET + SMEMBERS + ZSCORE + SCARD | Full dump of a model's cache state. |
| `analyzeIndexes()` | HLEN + SCAN + SCARD × N + ZCARD × N | Cardinality report per index. |
| `explain()` | — | Returns `ExplainResult` instead of executing. |
| `debug()` | — | Enables verbose logging of Redis operations. |

## Configuration

All options with defaults:

```php
// config/redis-model-cache.php
'connection' => env('REDIS_MODEL_CACHE_CONNECTION', 'cache'),
'default_ttl' => env('REDIS_MODEL_CACHE_TTL', 86400),           // 24h
'hydrate_batch_size' => env('REDIS_MODEL_CACHE_HYDRATE_BATCH', 5000),
'scan_count' => env('REDIS_MODEL_CACHE_SCAN_COUNT', 1000),

// Performance features
'stampede_protection' => [
    'enabled' => env('REDIS_MODEL_CACHE_STAMPEDE', false),
    'lock_timeout' => 10,    // seconds: lock TTL
    'wait_timeout' => 5,     // max wait before fallback
    'wait_interval' => 100,  // ms: polling interval
],
'stale_while_revalidate' => [
    'enabled' => env('REDIS_MODEL_CACHE_SWR', false),
    'grace_period' => 300,   // 5 minutes
    'queue' => 'default',
],

// Memory
'compression' => [
    'enabled' => env('REDIS_MODEL_CACHE_COMPRESS', false),
    'algorithm' => 'gzip',   // gzip | zstd | lz4
    'level' => 6,
    'min_size' => 512,       // bytes: skip small payloads
],
'multi_tenant' => [
    'enabled' => env('REDIS_MODEL_CACHE_MULTI_TENANT', false),
    'strategy' => 'header',  // header | subdomain | auth | session
    'key' => 'X-Tenant-ID',
],

// Invalidation
'invalidation' => [
    'strategy' => 'sync',    // sync | async
    'versioned' => env('REDIS_MODEL_CACHE_VERSIONED', false),
    'queue' => 'default',
],
'lua_scripting' => [
    'enabled' => env('REDIS_MODEL_CACHE_LUA', true),
],

// Observability
'observability' => [
    'enabled' => env('REDIS_MODEL_CACHE_OBSERVABILITY', true),
    'dispatch_events' => env('REDIS_MODEL_CACHE_EVENTS', true),
    'telescope' => env('REDIS_MODEL_CACHE_TELESCOPE', true),
    'pulse' => env('REDIS_MODEL_CACHE_PULSE', true),
    'debug' => env('REDIS_MODEL_CACHE_DEBUG', false),
],
```

### Operations Matrix

| Command | Action | Safe for Production? | Redis Impact |
|---------|--------|---------------------|--------------|
| `php artisan redis:monitor-cache info` | Redis INFO + keyspace summary | ✅ Read-only | O(1) per key-type scan |
| `php artisan redis:monitor-cache keys` | List keys by pattern | ✅ SCAN-based | O(N) cursor scan, configurable batch |
| `php artisan redis:monitor-cache ttl` | Detect keys without TTL | ✅ SCAN-based | O(N) cursor scan |
| `php artisan redis:monitor-cache memory` | Memory by key pattern | ✅ SCAN-based | O(N) cursor scan + data reads |
| `php artisan redis:monitor-cache clear` | Delete keys | ⚠️ Requires confirmation | SCAN + DEL |
| `php artisan redis-cache:debug` | Inspect service state | ✅ Read-only | Config inspection only |
| `php artisan redis-model-cache:warmup` | Pre-populate cache | ⚠️ Batch write | Pipeline EVALSHA × N |

No command uses `KEYS`. All pattern-matching uses `SCAN` with configurable `scan_count`.

## License

MIT. See [LICENSE](LICENSE).

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release history.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for development setup and guidelines.

## Security

See [SECURITY.md](SECURITY.md) for reporting vulnerabilities.
