# v2.0.0 — Full-Featured Redis Model Cache

## Breaking Changes

| Change | Before | After |
|--------|--------|-------|
| Key prefix format | `model_cache:{table}:...` | `{table}:model_cache:...` — hash tags for cluster safety |
| Config structure | Minimal keys | Full feature-toggled config (stampede, SWR, compression, lua, observability) |
| `store()` visibility | `protected` | `public` (needed for trait integration) |
| `computeStaleIndexKeys()` | standalone | Extracted `computeStaleIndexKeysFromData()` for batch HMGET path |

## New Features

- **Stampede Protection** — Redis lock (`SET NX EX`) with CAS Lua release prevents concurrent rebuilds on cache misses
- **Stale-While-Revalidate (SWR)** — Serve stale data immediately, dispatch background `RevalidateCacheJob` queue job to refresh
- **Query Engine** — `whereIn` (SUNION), `whereBetween` (ZRANGEBYSCORE), `orWhere` (set union), `pluck` (partial hydration, 60–80% memory reduction)
- **Incremental Updates** — `updateAttribute`/`updateAttributes` with automatic index migration (old → new set entry cleanup)
- **Background Warmup** — `redis-model-cache:warmup` Artisan command with progress bar, chunked processing, memory stats
- **Compression** — gzip/zstd/lz4 auto-detection on serialize, transparent decompression on hydrate (57% memory reduction with gzip)
- **Multi-Tenant Support** — `TenantResolverInterface` for tenant-scoped key namespacing
- **Lua Atomicity** — `EVALSHA` with SHA caching and `NOSCRIPT` fallback; CAS script for stampede lock release
- **Redis Cluster Support** — hash tags (`{table}`) on all keys keep model data on the same cluster node
- **Events** — `CacheHit`, `CacheMiss`, `QueryExecuted` dispatched with full telemetry data
- **Observability** — Metrics collector (hit rates, latency P50/P95/P99, pipeline stats), debug mode header
- **Debug Tooling** — `inspect()` (key contents), `analyzeIndexes()` (cardinality/memory), `explain()` (query plan)
- **Telescope Integration** — `ModelCacheWatcher` records cache operations in Laravel Telescope
- **Pulse Integration** — `ModelCacheRecorder` provides live cache metrics cards

## Performance

- **Write throughput**: ~17,677 models/s at batch 1,000; ~21,000 models/s at batch 5,000
- **Read latency**: P50 ~0.89ms, P99 ~4.54ms (batch 500)
- **Pluck**: 50.6% faster than full hydration; 60–80% less memory
- **Compression**: gzip 57% memory reduction; zstd 55% (2–3× faster decompress than gzip)
- **Configurable batch sizes**: `hydrate_batch_size` (default 5000)

## Requirements

- PHP `^8.4`
- Laravel `^12.0`

## Tests

- 124 tests (unit + feature), 277 assertions
- Pint PSR‑12 enforcement — zero warnings
- PHPStan level 8 — zero errors
- Benchmark suite in `benchmarks/` (throughput, latency, memory)

## Chores

- Restructured `README.md` from 965 lines → ~90-line landing page with `docs/` sub-documents
- Added `phpstan.stub` for optional Telescope/Pulse dependencies
- Added `MILESTONE_ROADMAP.md` with all 8 milestone definitions
- Added `PERFORMANCE.md` with full benchmark results and tuning guide
- Added `.github/workflows/benchmarks.yml` for CI benchmark regression detection
