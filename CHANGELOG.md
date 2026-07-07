# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [v2.7.0] — 2026-07-07

### API Consistency — Deprecations & Command Naming (Phase 3D)

#### Added

- **Command aliases for `redis-model-cache:*` prefix** — all console commands now use the canonical `redis-model-cache:*` prefix as their primary signature:
  - `php artisan redis-model-cache:debug` (legacy alias: `redis-cache:debug`)
  - `php artisan redis-model-cache:monitor-cache` (legacy alias: `redis:monitor-cache`)
  - `php artisan redis-model-cache:warmup` (already canonical, no change)
- **Command alias test suite** — `ConsoleCommandTest` (6 tests) verifying correct signatures and backward-compatible aliases.

#### Changed

- **`DebugCommand`** — primary signature changed from `redis-cache:debug` to `redis-model-cache:debug`; old name registered as legacy alias.
- **`MonitorCacheCommand`** — primary signature changed from `redis:monitor-cache` to `redis-model-cache:monitor-cache`; old name registered as legacy alias.

#### Deprecated

- **`RedisModelService::all()`** — already throws `BadMethodCallException`; now marked `@deprecated` with explicit guidance in exception message.
- **`ModelCacheService::all()`** — interface method marked `@deprecated`.
- **`RedisModelService::selective()`** — thin wrapper around `pluck()`. Marked `@deprecated` — use `pluck()` instead.
- **`ModelCacheService::selective()`** — interface method marked `@deprecated`.

#### Documentation

- README command examples updated to use `redis-model-cache:*` prefix with legacy alias notes.
- `docs/architecture.md` command list updated to reflect canonical names.
- README partial hydration section updated to recommend `pluck()` over deprecated `selective()`.
- API reference table marks `selective()` as deprecated.

## [v2.6.0] — 2026-07-07

### Typed Configuration DTO (Phase 3C)

#### Added

- **`Configuration` typed DTO** (`src/Support/Configuration.php`) — 28 typed readonly properties covering all `redis-model-cache` config keys with proper `int`, `string`, `bool`, and `?int`/`?string` types. Factory method `Configuration::fromConfig()` reads from Laravel's config with full type coercion.
- **Configuration test suite** — 7 tests covering defaults, custom values, `fromConfig()` round-trip, empty config fallback, explicit null handling, and nullable fields.

#### Changed

- **`RedisBaseService`** — accepts optional `?Configuration $configuration` parameter; resolves via `Configuration::fromConfig()` when omitted. All internal config reads use typed properties instead of raw `config()` calls.
- **`RedisModelService`** — accepts optional `?Configuration $configuration` parameter (passed through to parent). `metricsEnabled`, `hydrateBatchSize`, `scanCount`, `debugMode`, compression, lua, SWR, stampede protection, and observability dispatch all read from the typed DTO.
- **`DefaultConnectionResolver`** — accepts optional `?Configuration $configuration`; resolves connection name from DTO.
- **`HasRedisModelCache` trait** — `resolveInvalidationManager()` reads invalidation config from `Configuration::fromConfig()` instead of raw `config()` array access.
- **`DebugCommand`** — `renderConfig()` takes a typed `Configuration` parameter; all display values from typed properties.
- **`RevalidateCacheJob`** / **`InvalidateModelCacheJob`** — config reads via `Configuration::fromConfig()`.
- **ServiceProvider** — bindings resolve config via `Configuration::fromConfig()`; `registerEventSubscribers()` uses typed DTO.

#### PHPStan

- Removed 9 suppression patterns related to mixed config access; updated comments for remaining mixed-value suppressions (covers Redis responses, model attributes, deserialized payloads — inherent to dynamic data). PHPStan level max: 0 errors.

## [v2.5.1] — 2026-07-07

### CI/CD Consolidation

#### Added

- **Consolidated CI workflow** — `.github/workflows/ci.yml` combines Pint, PHPStan, and PHPUnit into a single workflow with PHP 8.3/8.4 × Laravel 11/12 matrix, including prefer-lowest on PHP 8.3 + Laravel 11. Runs on push/PR to `main`/`master`. Redis service container with health check. Explicit `REDIS_HOST: redis` env var for reliable service discovery.

#### Changed

- **README badge** — updated CI badge to point to `ci.yml` workflow.

## [v2.5.0] — 2026-07-07

### Octane-Safe State (Scoped Service)

#### Added

- **`RedisModelCacheState` scoped state service** — `src/Support/RedisModelCacheState.php` holds the processing and deleted-in-cycle tracking previously stored in static arrays. Registered as a Laravel scoped binding (`$this->app->scoped()`), it is automatically reset between requests in Octane workers.
- **State isolation test suite** — 17 tests covering per-class independence, multi-ID tracking, flush semantics, instance isolation, and string key support.

#### Changed

- **`HasRedisModelCache` trait** — static arrays `$redisModelCacheProcessing` and `$redisModelCacheDeletedInCycle` replaced with the scoped `RedisModelCacheState`. All protected methods (`isRedisModelCacheProcessing`, `markRedisModelCacheProcessing`, `unmarkRedisModelCacheProcessing`, `isRedisModelCacheDeletedInCycle`, `markRedisModelCacheDeletedInCycle`) now delegate to the state service.
- **`RedisModelCacheServiceProvider::registerLifecycleHooks()`** — `App::terminating` and Octane `WorkerTickStarting` hooks now flush via the scoped state service instead of the static trait method. The scoped binding naturally resets between Octane requests.
- **`flushRedisModelCacheProcessing()`** — preserved as a backward-compatible public method that delegates to the scoped state service.

#### Removed

- **Static request-cycle state** — `HasRedisModelCache::$redisModelCacheProcessing` and `HasRedisModelCache::$redisModelCacheDeletedInCycle` static arrays removed. No more static state bleed across requests in long-running workers.

## [v2.4.0] — 2026-07-07

### Public Release Readiness

#### Added

- **`WarmupCommand` registered in service provider** — `php artisan redis-model-cache:warmup` now available as a registered console command matching README documentation
- **GitHub Actions CI workflow** — `.github/workflows/run-tests.yml` with PHP 8.3/8.4 × Laravel 11/12 matrix, including prefer-lowest on PHP 8.3 + Laravel 11; runs Pint, PHPStan, and PHPUnit
- **Provider validation test suite** — `ServiceProviderTest` covers config key presence, default values, scan_strategy validation, TTL validation, stampede protection validation, and SWR validation

#### Changed

- **Service provider boot** — `Console\WarmupCommand::class` added to the commands registered in `runningInConsole()` block

## [v2.3.0] — 2026-07-06

### Production Polish

#### Added

- **CI matrix expansion** — PHP 8.3/8.4/8.5 × Laravel 11/12 × prefer-lowest/prefer-stable; Codecov on PHP 8.3 + Laravel 12; PHPStan on PHP 8.3 + Laravel 12
- **Architecture diagrams** — `docs/diagrams/` with 4 SVGs: request flow, key layout, query resolution, invalidation lifecycle
- **ADRs** — `docs/adr/0001-0005` covering indexed-only queries, deterministic behavior, no automatic index generation, no silent DB fallback, Redis hash/set choice
- **Repository polish** — CodeQL analysis workflow, Dependabot config, Pint standalone workflow, expanded PR template, compatibility/benchmark issue templates
- **Benchmark automation** — `scripts/run-benchmarks.sh` runner, `docs/benchmarks/report.md`, `benchmarks.yml` CI workflow, fixed bootstrap
- **Edge-case test coverage** — `EdgeCaseTest` (11 tests): Redis connection exceptions, corrupted payloads, empty/null results, async invalidation, delete of uncached model

#### Changed

- **Composer constraints widened** — PHP `^8.3`, Illuminate `^11.0 || ^12.0`, Testbench `^9.0 || ^10.11`
- **README claims audit** — removed unsubstantiated "production-tested" and blanket O(1) claims; qualified performance characteristics with explicit complexity table
- **Docs reorganization** — technical docs moved from root to `docs/`; root now only contains README, CHANGELOG, CONTRIBUTING, SECURITY, LICENSE
- **PHPStan raised to level max** — documented ignoreErrors with rationale for config-derived mixed types, Telescope stubs, and Mockery conventions
- **Type improvements** — `RequestTenantResolver` return types narrowed; `CacheManager` config access cast; `QueryPlanner` mixed concat fixed; `ResolvedIndex` keys parameter widened
- **Benchmark scripts** — all 4 benchmarks now properly register the service provider via `benchmarks/bootstrap.php`
- **`hydrateIds()`** — early return for empty ID arrays restored
- **Internal doc links** — all references from root docs updated to `docs/` paths

## [v2.2.0] — 2026-07-06

#### Added

- **Lua atomic store: zero string parsing** — `LUA_ATOMIC_STORE` script rewritten to use mathematical offset indexing with discrete `ARGV[4..7]` counts and individual `ARGV[8+Q]` score entries. Eliminates `string.gmatch` parsing and Lua GC pressure under high-throughput stores.
- **Batch EVALSHA pipelining** — `storeMany()` pipelines EVALSHA commands with explicit `SCRIPT LOAD` priming before pipeline entry. Guarantees atomic batch writes without NOSCRIPT fallback within the batch.
- **Explicit script priming** — `primeAtomicStoreScript()` loads `LUA_ATOMIC_STORE` into Redis cache before pipeline enters, ensuring all EVALSHA calls within the batch succeed on first attempt.
- **Client-agnostic EVALSHA dispatch** — `queueLuaAtomicStoreOnClient()` handles both phpredis and Predis eval/evalSha signatures on direct connections and pipeline objects.

#### Changed

- **`storeModel()`** — now uses Lua atomic store in all execution paths (direct AND pipeline), not only when `$pipeline === null`. This extends atomicity guarantees to the batch write path.
- **`storeModelAtomic()`** — accepts optional `$pipeline` and `$precomputedStaleKeys` parameters for pipeline-mode execution.
- **`StampedeProtection::waitForLock()`** — exponential backoff with randomized jitter (`base * 2^attempt` ms, `random_int(0, sleepMs/2)` jitter). Initial de-synchronization jitter prevents thundering herd on first poll.
- **`Observability` ring buffers** — `latencySamples` and `pipelineSizes` converted to bounded ring buffers (1000 items each) with modulo-based circular indexing. `flattenRingBuffer()` handles wraparound correctly for percentile calculations.
- **Lifecycle hooks** — `registerLifecycleHooks()` hooks `flushRedisModelCacheProcessing()` into `App::terminating` and Octane `WorkerTickStarting` events. Prevents static state bleed between requests.
- **`MonitorCacheCommand` production safety** — all `KEYS` calls replaced with cursor-based `SCAN` via `scanKeys()`. Supports both phpredis and Predis clients.

#### Removed

- **String parsing in Lua** — `string.gmatch` and comma-split score parsing removed from `LUA_ATOMIC_STORE`.

#### Fixed

- **Pipeline Lua bypass** — `storeModel()` no longer skips Lua when a pipeline is provided. Previously the batch path fell back to individual Redis commands even when Lua scripting was enabled.
- **Observability ring buffer access** — `latencySamples()` and statistical methods now use `flattenRingBuffer()` so partially filled or wrapped buffers report correct values.
- **Monitor command emoji output** — replaced platform-dependent emoji indicators with ASCII-compatible markers.

## [v2.1.0] — 2026-07-06

### Added

- **Multi-tenant cache isolation** — `{tenant:{id}:{table}}` key prefixing via `TenantResolverInterface` with built-in `RequestTenantResolver` supporting header, subdomain, auth, and session strategies.
- **Concurrency safety test suite** — 18 tests covering stampede lock acquire/wait/timeout/CAS, concurrent read-modify-write, Redis failure simulation, and invalidation consistency.
- **GitHub Actions CI** — PHPUnit with `--coverage-clover`, Codecov upload step, coverage badge in README.
- **CHANGELOG.md, CONTRIBUTING.md, SECURITY.md** — GitHub community health files.
- **PR template and issue templates** — `PULL_REQUEST_TEMPLATE.md`, `bug_report.md`, `feature_request.md`.

### Changed

- **README rewrite** — production-grade documentation with API reference tables, configuration reference, and cross-linked architecture docs.
- **ARCHITECTURE.md** — comprehensive architecture documentation covering key layout, data flow, design decisions, and Redis command inventory.
- **QUERY_LIMITATIONS.md** — complete table of supported/invalid operations with rationale.
- **INVALIDATION.md** — lifecycle hooks, versioning strategy, parent touches, edge case documentation.

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
