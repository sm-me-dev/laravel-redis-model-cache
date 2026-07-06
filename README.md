<p align="center">
    <h1 align="center">Laravel Redis Model Cache</h1>
    <p align="center">Deterministic Redis model caching for Laravel Eloquent — hash-based, index-aware.</p>
</p>

<p align="center">
    <a href="https://packagist.org/packages/sm-me/laravel-redis-model-cache"><img src="https://img.shields.io/packagist/v/sm-me/laravel-redis-model-cache" alt="Latest Version"></a>
    <a href="https://packagist.org/packages/sm-me/laravel-redis-model-cache"><img src="https://img.shields.io/packagist/php-v/sm-me/laravel-redis-model-cache" alt="PHP Version"></a>
    <a href="https://packagist.org/packages/sm-me/laravel-redis-model-cache"><img src="https://img.shields.io/packagist/l/sm-me/laravel-redis-model-cache" alt="License"></a>
    <a href="https://codecov.io/gh/sm-me/laravel-redis-model-cache"><img src="https://codecov.io/gh/sm-me/laravel-redis-model-cache/branch/main/graph/badge.svg" alt="Code Coverage"></a>
    <a href="https://github.com/sm-me/laravel-redis-model-cache/actions"><img src="https://github.com/sm-me/laravel-redis-model-cache/actions/workflows/run-tests.yml/badge.svg" alt="Tests"></a>
    <a href="https://github.com/sm-me/laravel-redis-model-cache/actions"><img src="https://github.com/sm-me/laravel-redis-model-cache/actions/workflows/static-analysis.yml/badge.svg" alt="Static Analysis"></a>
    <a href="https://github.com/sm-me/laravel-redis-model-cache/actions"><img src="https://github.com/sm-me/laravel-redis-model-cache/actions/workflows/codeql-analysis.yml/badge.svg" alt="CodeQL"></a>
</p>

---

**v2.2.0** | PHP ^8.3 | Laravel ^11.0 || ^12.0

---

## Overview

Laravel Redis Model Cache replaces Eloquent's per-row query pattern with deterministic Redis hash lookups. Every model is serialized once into a Redis hash, referenced by index sets (Redis Sets/Sorted Sets) for indexed lookup, and invalidated via lifecycle hooks.

Unlike generic key-value caches, this package is **index-aware**: queries must use declared indexed fields, enabling SINTER/SMEMBERS lookups instead of HSCAN or KEYS scans. Full hash scans are blocked at the API level.

### Key capabilities

- **Hash-based storage** — all model data (attributes + eager-loaded relations) in one Redis Hash per table
- **Index-driven queries** — Redis Sets for equality lookups, Sorted Sets for range queries, SUNION for OR logic
- **Deterministic invalidation** — model lifecycle (saved/deleted/forceDeleted) triggers exact index cleanup via `HasRedisModelCache` trait; no TTL-based eventual consistency
- **Stampede protection** — Redis `SET NX EX` lock with exponential-backoff polling, jitter, and fail-fast timeouts
- **Stale-while-revalidate** — serve stale data during TTL expiry while a queue job refreshes in background
- **Atomic Lua stores** — zero string parsing, mathematical offset indexing, discrete ARGV counts
- **Batch atomic writes** — `storeMany()` pipelines EVALSHA commands with explicit script priming
- **Incremental updates** — `updateAttribute`/`updateAttributes` modify a single field without full re-serialization
- **Compression** — gzip/zstd/lz4 with `min_size` threshold to skip CPU waste on small payloads
- **Partial hydration** — `selective()` / `pluck()` fetch only requested fields, reducing memory 60-80% vs full model hydration
- **Multi-tenant isolation** — `{tenant:{id}:{table}}` prefix via `TenantResolverInterface`
- **Cluster-safe** — hash tags `{...}` keep all keys for a model on the same cluster node
- **Observability** — `CacheHit`/`CacheMiss`/`QueryExecuted` events, debug mode, inspect/analyzeIndexes tooling
- **Octane-ready** — bounded ring buffers, lifecycle state flushing, no static bleed between requests

## Architecture

See the architecture documentation and diagrams:

| Document | Audience |
|----------|----------|
| [`docs/architecture.md`](docs/architecture.md) | Engineers — data flow, key layout, Redis ops, design decisions |
| [`docs/query-limitations.md`](docs/query-limitations.md) | All queries — what works, what doesn't, why |
| [`docs/invalidation.md`](docs/invalidation.md) | Engineers — lifecycle hooks, versioning, edge cases, parent touches |
| [`docs/observability.md`](docs/observability.md) | Operators — events, metrics, debug, Telescope/Pulse |
| [`docs/performance.md`](docs/performance.md) | Decision-makers — throughput, latency, memory |
| [`docs/benchmarks/report.md`](docs/benchmarks/report.md) | Performance measurements and methodology |
| [`docs/adr/`](docs/adr/) | Architecture Decision Records |
| [`docs/diagrams/`](docs/diagrams/) | Architecture diagrams (SVG) |

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

- PHP 8.3+
- Laravel 11 or 12
- Redis (cluster or single-node)
- `ext-redis` (phpredis) or `predis/predis`

## Performance Characteristics

The package replaces per-row Eloquent queries with batched Redis hash lookups. Performance depends on index set cardinality, number of indexes intersected, and payload size.

### Query complexity

| Method | Redis Time | Network Round Trips |
|--------|-----------|---------------------|
| `find(id)` | O(1) | 1 |
| `where()` single index | O(N) on set size | 1 + HMGET batch |
| `where()` multi-index | O(N1 + N2 + ...) | 1 + HMGET batch |
| `whereBetween()` | O(log N + M) | 1 + HMGET batch |
| `whereIn()` | O(N1 + N2 + ...) | 1 + HMGET batch |
| `count()` single index | O(1) | 1 |
| `exists()` single index | O(1) | 1 |
| `store()` | O(K) where K = indexes + sorted | 1 (Lua) or pipeline |
| `storeMany(N)` | O(N × K) | 2 (HMGET + pipeline) |

> **Note:** These are asymptotic bounds. Real-world performance varies by hardware, Redis version, network latency, and data size. See [`docs/performance.md`](docs/performance.md) for detailed benchmarks and methodology.

### Key design properties

- **No `KEYS` commands** — all pattern matching uses cursor-based `SCAN`
- **No `all()` method** — full hash scans are blocked at the API level
- **No silent database fallback** — cache behavior is deterministic
- **No automatic index generation** — indexes must be explicitly declared
- **Deterministic query plans** — `explain()` returns exact commands without executing them

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

#### Query Methods

| Method | Redis Ops | Notes |
|--------|-----------|-------|
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

#### Mutation Methods

| Method | Redis Ops | Notes |
|--------|-----------|-------|
| `store($model)` | EVALSHA (Lua atomic) or pipeline | Direct store with atomic Lua. Fallback to pipeline when Lua unavailable. |
| `storeMany($models)` | Pipeline(EVALSHA × N) with explicit script priming | Batch store, single HMGET for stale reads, EVALSHA per model. |
| `delete($id)` | HGET + HDEL + SREM × N + ZREM × N | Reads old data to clean up stale index entries. |
| `updateAttribute($id, $field, $value)` | HGET + pipeline(HSET + SREM/SADD) | Incremental, preserves relations. |
| `updateAttributes($id, $attrs)` | HGET + pipeline(HSET + SREM/SADD) | Batch incremental, preserves relations. |
| `bustVersion()` | HINCRBY | Increments version counter in meta. |
| `clear()` | SCAN + DEL | Clears hash + indexes + sorted + custom. |
| `clearAll()` | SCAN + DEL | All keys for this prefix. |

#### Debug / Observability

| Method | Redis Ops | Notes |
|--------|-----------|-------|
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
