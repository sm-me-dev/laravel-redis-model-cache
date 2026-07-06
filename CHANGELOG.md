# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [v2.2.0] — 2026-07-06

### Added

- **Lua atomic store: zero string parsing** — `LUA_ATOMIC_STORE` script rewritten to use mathematical offset indexing with discrete `ARGV[4..7]` counts and individual `ARGV[8+Q]` score entries. Eliminates `string.gmatch` parsing and Lua GC pressure under high-throughput stores.
- **Batch EVALSHA pipelining** — `storeMany()` pipelines EVALSHA commands with explicit `SCRIPT LOAD` priming before pipeline entry. Guarantees atomic batch writes without NOSCRIPT fallback within the batch.
- **Explicit script priming** — `primeAtomicStoreScript()` loads `LUA_ATOMIC_STORE` into Redis cache before pipeline enters, ensuring all EVALSHA calls within the batch succeed on first attempt.
- **Client-agnostic EVALSHA dispatch** — `queueLuaAtomicStoreOnClient()` handles both phpredis and Predis eval/evalSha signatures on direct connections and pipeline objects.

### Changed

- **`storeModel()`** — now uses Lua atomic store in all execution paths (direct AND pipeline), not only when `$pipeline === null`. This extends atomicity guarantees to the batch write path.
- **`storeModelAtomic()`** — accepts optional `$pipeline` and `$precomputedStaleKeys` parameters for pipeline-mode execution.
- **`StampedeProtection::waitForLock()`** — exponential backoff with randomized jitter (`base * 2^attempt` ms, `random_int(0, sleepMs/2)` jitter). Initial de-synchronization jitter prevents thundering herd on first poll.
- **`Observability` ring buffers** — `latencySamples` and `pipelineSizes` converted to bounded ring buffers (1000 items each) with modulo-based circular indexing. `flattenRingBuffer()` handles wraparound correctly for percentile calculations.
- **Lifecycle hooks** — `registerLifecycleHooks()` hooks `flushRedisModelCacheProcessing()` into `App::terminating` and Octane `WorkerTickStarting` events. Prevents static state bleed between requests.
- **`MonitorCacheCommand` production safety** — all `KEYS` calls replaced with cursor-based `SCAN` via `scanKeys()`. Supports both phpredis and Predis clients.

### Removed

- **String parsing in Lua** — `string.gmatch` and comma-split score parsing removed from `LUA_ATOMIC_STORE`.

### Fixed

- **Pipeline Lua bypass** — `storeModel()` no longer skips Lua when a pipeline is provided. Previously the batch path fell back to individual Redis commands even when Lua scripting was enabled.
- **Observability ring buffer access** — `latencySamples()` and statistical methods now use `flattenRingBuffer()` so partially filled or wrapped buffers report correct values.
- **Monitor command emoji output** — replaced platform-dependent emoji indicators with ASCII-compatible markers.

## [v2.1.0] — 2026-07-06

### Added

- **Multi-tenant cache isolation** — `{tenant:{id}:{table}}` key prefixing via `TenantResolverInterface` with built-in `RequestTenantResolver` supporting header, subdomain, auth, and session strategies.
- **Concurrency safety test suite** — 18 tests covering stampede lock acquire/wait/timeout/CAS, concurrent read-modify-write, Redis failure simulation (connection refused, timeout, Lua failure, SCAN failure), and invalidation consistency.
- **GitHub Actions CI** — PHPUnit with `--coverage-clover`, Codecov upload step, coverage badge in README.
- **CHANGELOG.md, CONTRIBUTING.md, SECURITY.md** — GitHub community health files.
- **PR template and issue templates** — `PULL_REQUEST_TEMPLATE.md`, `bug_report.md`, `feature_request.md`.

### Changed

- **README rewrite** — production-grade documentation with API reference tables, configuration reference, and cross-linked architecture docs.
- **ARCHITECTURE.md** — comprehensive architecture documentation covering key layout, data flow, design decisions, and Redis command inventory.
- **QUERY_LIMITATIONS.md** — complete table of supported/invalid operations with rationale.
- **INVALIDATION.md** — lifecycle hooks, versioning strategy, parent touches, edge case documentation.

### Fixed

- (none)

## [v2.0.0] — 2026-07-02

### Added

- **Deterministic invalidation engine** — `InvalidationManager` with sync/async strategies, versioned invalidation with HINCRBY meta counter, `InvalidationContext` for full event traceability.
- **Redis index resolver** — `IndexResolver` maps where clauses to Redis commands (`SMEMBERS` for single index, `SINTER` for multi-index) with field validation.
- **Query planner** (`QueryPlanner`) — explain mode returns deterministic execution plans without running Redis commands.
- **Relation-aware serialization** — eager-loaded relations (HasMany, BelongsTo, HasOne, MorphMany, BelongsToMany) are serialized recursively and restored on hydration.
- **Custom indexes** — `customIndexKey()`, `rememberCustom()`, `customWhere()` for application-defined index sets with optional sorted-set ordering.
- **`orWhere()`** — combine index result sets via SUNION + array merge.
- **Incremental attribute updates** — `updateAttribute()` and `updateAttributes()` modify serialized payload without full re-serialization; stale index entries are cleaned atomically.
- **Stampede protection** — `StampedeProtection` with `SET NX EX` locks, CAS release via Lua, and polling wait with configurable timeout.
- **Stale-while-revalidate (SWR)** — serve stale data within grace period while `RevalidateCacheJob` refreshes cache in the background.
- **Compression** — gzip/zstd/lz4 support with `min_size` threshold to skip CPU waste on small payloads.
- **Observability events** — `CacheHit`, `CacheMiss`, `QueryExecuted` with full context (query, execution time, command count).
- **`selective()`** — lightweight field-only fetch returning arrays, 60–80% less memory than full model hydration.
- **`pluck()`** — extract specific attributes from cached models without full hydration.
- **`find()`, `first()`, `count()`, `exists()`** — hash-direct and index-direct lookup/aggregation methods.
- **Batch HMGET** — `hydrateIds()` and `pluck()` use HMGET with configurable batch size for O(1) round trips.
- **Multi-tenant foundation** — `TenantResolverInterface` and key prefixing in `buildPrefix()`.
- **Artisan commands** — `redis-model-cache:warmup` (pre-populate cache) and `redis-cache:debug` (inspect state, metrics, config).
- **Explain mode** — `$cache->explain()->where(...)` returns `ExplainResult` with deterministic command/keys/cardinality plan.
- **Cache manager** (`CacheManager`) — metrics collector tracking hit rate, latency percentiles, lock contention.
- **Telescope watcher and Pulse card** — optional integrations for Laravel's monitoring ecosystem.
- **`ModelCacheInvalidated` event** — dispatched on invalidation for external consumers.

### Changed

- **`storeMany()`** — now uses batched HMGET for stale index reads instead of N individual HGETs (single round-trip vs N).
- **`rememberAll()`** — full rewrite: SWR, stampede protection, where/post-store where, cache miss events.
- **`buildPrefix()`** — cluster hash tag format `{table}` for all key types.
- **Serialization format** — structured `{attributes, relations}` payload supporting eager-loaded relations.

### Removed

- **`all()`** — permanently disabled. Full hash scans are blocked at the API level. Use `where()` with indexed fields.

### Fixed

- **Stale index cleanup** — `storeModel()` now reads old hash data before pipeline and SREM stale index entries.
- **TTL propagation** — every key type (hash, index sets, sorted sets, custom indexes, meta) receives EXPIRE.
- **Pipeline execution** — phpredis and Predis compatibility via `executePipeline()` pattern detection.

## [v1.0.0] — 2026-06-15

### Added

- Initial release: hash-based model caching with index lookups.
- `RedisModelService` with `where()`, `store()`, `delete()`, `clear()`, `clearAll()`.
- `HasRedisModelCache` trait with auto-sync on save/delete.
- Basic index and sorted-set support.
- `RedisConnectionResolver` for connection abstraction.
- `ModelMatchStrategy` for value normalization/matching.
- Package configuration via `redis-model-cache.php`.
- Orchestra Testbench test suite.
