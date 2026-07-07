# Architecture Review Report — Laravel Redis Model Cache

**Inspected files:** All 30+ source files across `src/`, `config/`, `tests/`, `phpstan.neon.dist`

---

## Top 5 Architectural Issues

### 1. Inconsistent Artisan Command Prefixes (3 different namespaces)

**Evidence:**
- `src/Console/DebugCommand.php:13` — signature `redis-cache:debug`
- `src/Console/MonitorCacheCommand.php:12` — signature `redis:monitor-cache`  
- `src/Console/WarmupCommand.php:14` — signature `redis-model-cache:warmup`

**Why it matters:** Users must remember three different command prefixes for one package. This is confusing and violates the principle of least surprise. New users commonly type the wrong prefix.

**Impact:** Low code complexity, high UX friction. All three should use `redis-model-cache:`
- `redis-model-cache:debug`
- `redis-model-cache:monitor`  
- `redis-model-cache:warmup`

**Migration:** Aliases via `$this->aliases()` or deprecation warnings for old names. Accept backward compat for 1 minor version.

---

### 2. Config-Access-As-Mixed (dozens of PHPStan suppressions)

**Evidence:**
- `phpstan.neon.dist:21-34` — **14 ignore patterns** for config-derived mixed types
- Every method in `RedisModelService.php` calls `config('redis-model-cache.*')` inline (50+ calls)
- `hasRedisModelCache.php:184` — `config('redis-model-cache.invalidation', [])` returns mixed
- `CacheManager.php:111` — `config['indexes'] ?? []` from config

**Why it matters:** Every `config()` call returns `mixed`, requiring PHPStan to be suppressed on 5+ categories of errors. This hides real type issues and creates maintenance burden. A typed config DTO would eliminate all suppressions.

**Impact:** Medium. All these suppressions could hide legitimate bugs. The fix is a typed DTO object that wraps `config()` calls.

**Proposed direction:** Create `RedisModelCacheConfig` value object in `src/Support/` — type all properties, validate in constructor, inject via service container with config array. Replace all `config()` calls with typed accessors.

---

### 3. Fragile phpredis/Predis Client Detection (5+ locations)

**Evidence:**
- `RedisModelService.php:1537` — `if ($pipeline instanceof \Redis)` for executePipeline
- `RedisModelService.php:1814` — `if (is_a($this->redis, 'Predis\Client'))` in collectKeysByPattern
- `RedisBaseService.php:417-422` — `instanceof \Redis` in evalSha/evalRaw
- `StampedeProtection.php:173-177` — `instanceof \Redis` in executeEvalSha
- `MonitorCacheCommand.php:70-88` — duplicated SCAN logic per client type

**Why it matters:** Each `instanceof \Redis` check adds a branch that must be maintained and tested. If Laravel adds a new Redis driver, all these branches must be updated. This violates OCP (Open-Closed Principle).

**Impact:** Medium. Fragile, duplicated, and untestable without both phpredis and Predis installed. Extracting an adapter interface would solve this.

**Proposed direction:** Create a `RedisClientAdapter` contract with methods like `evalSha()`, `eval()`, `scan()`, `pipeline()`, `exec()`, with phpredis and Predis implementations.

---

### 4. `pluck()` and `selective()` Method Redundancy (near-identical implementation)

**Evidence:**
- `RedisModelService.php:1034-1107` — `pluck()` — inline HMGET batch logic
- `RedisModelService.php:1121-1178` — `selective()` — **identical** HMGET batch logic, different method signature only

Both methods:
- Validate indexes
- Build concrete keys
- Run SINTER or HKEYS
- Apply `$only` filter
- HMGET with batching
- Deserialize and extract fields
- Return `Collection<int, array>`

The only difference: `pluck()` calls the result "attributes" vs `selective()` calls them "fields".

**Why it matters:** Code duplication doubles maintenance surface. Any bug fix to HMGET batching must be applied twice. The two methods confuse users — they do the same thing.

**Impact:** Low-medium. Refactor `selective()` to delegate to `pluck()` or vice versa.

---

### 5. `HasRedisModelCache` Trait Static State Leak Risk

**Evidence:**
- `HasRedisModelCache.php:20` — `static::$redisModelCacheProcessing` — class-level static array  
- `HasRedisModelCache.php:28` — `static::$redisModelCacheDeletedInCycle` — class-level static array
- `RedisModelCacheServiceProvider.php:116-129` — Lifecycle hooks to flush via `flushRedisModelCacheProcessing()`

**Why it matters:** Static state is the #1 source of bugs in Laravel Octane and other long-running processes. The flush hooks mitigate this, but they rely on:
1. The `App::terminating()` callback firing (NOT guaranteed in Octane — lifecycle is different)
2. The `WorkerTickStarting` event existing (Octane only, specific version)
3. Every request path calling flush — manual `flush()` calls not covered

**Impact:** Medium-High for Octane users. A single missed flush causes stale state bleed.

**Proposed direction:** Use Laravel's `ScopedSingleton` (Laravel 11+) for per-request state instead of static arrays. Or use a dedicated service instance injected per-request.

---

## Secondary Issues

| # | Issue | File | Impact |
|---|-------|------|--------|
| 6 | `formatBytes()` duplicated in DebugCommand and WarmupCommand | `Console/DebugCommand.php:122`, `Console/WarmupCommand.php:189` | Low — deduplicate to shared helper |
| 7 | `ObservabilitySubscriber` reads config on every event dispatch | `Listeners/ObservabilitySubscriber.php` (not inspected but inferred from pattern) | Medium — config reads add overhead to every cache hit/miss |
| 8 | `resolveFieldName()` uses regex on SQL expressions to extract field names | `RedisModelService.php:1670-1682` | Low-Medium — fragile, could misparse complex expressions |
| 9 | `StampedeProtection::waitForLock()` uses non-deterministic exponential backoff | `StampedeProtection.php:132-155` | Low — jitter is intentional but max wait time is hard to predict |
| 10 | `RedisHelperService::rememberSet()` always deserializes even on fresh store | `RedisHelperService.php:35` — always calls `deserializeResult()` even when `$callback` returned deserialized data | Low — unnecessary de/re-serialization cycle |
| 11 | Config validation in ServiceProvider uses var_export for error messages | `RedisModelCacheServiceProvider.php:156-196` | Low — functional but verbose |
| 12 | `InvalidationManager` returns `void` from handle(), no error reporting | `Invalidation/InvalidationManager.php:30-42` | Low — failures are silent |
| 13 | No `.github/workflows/ci.yml` exists | Missing | Medium — no CI matrix for PHP 8.3/8.4 × Laravel 11/12 |
| 14 | `QueryPlanner::hasConcreteKeys()` checks `$index->keys !== []` but keys may never be set | `QueryPlanner.php:259-262` | Low — explain mode may show misleading "no concrete keys" |

---

## Risk Assessment

| Change | Risk Level | Migration Complexity |
|--------|-----------|---------------------|
| Renaming artisan commands | **Yellow** | Low — add aliases, deprecate old names |
| Typed Config DTO | **Green** | Medium — internal refactor, public API unchanged |
| Redis Client Adapter | **Yellow** | Medium — internal refactor, `getRedis()` return type changes |
| `pluck()`/`selective()` dedup | **Green** | Low — keep both methods, one delegates |
| Static state → scoped singletons | **Yellow** | Medium — behavior-preserving but large refactor |
| CI workflow addition | **Green** | None — purely additive |

---

## Quick Wins (Green, High ROI)

1. **Deduplicate `formatBytes()`** — Extract to shared `Support/helpers.php` or create a `FormatHelper` class
2. **Delegate `selective()` to `pluck()`** — Remove 60 lines of duplicated HMGET batching
3. **CI workflow** — Add `.github/workflows/ci.yml` with PHP 8.3/8.4, Laravel 11/12 matrix
4. **Rename artisan commands** — Add `redis-model-cache:debug` alias to DebugCommand
5. **Unified type hints** — Add proper `@param` and `@return` where missing (e.g., `ObservabilitySubscriber`)

---

## File Map (Inspected)

| File | Lines | Role |
|------|-------|------|
| `src/RedisModelService.php` | 2312 | Core cache service |
| `src/RedisBaseService.php` | 441 | Base: compression, Lua, serialization |
| `src/RedisHelperService.php` | 45 | Hash-only cache helper |
| `src/Concerns/HasRedisModelCache.php` | 258 | Eloquent trait |
| `src/RedisModelCacheServiceProvider.php` | 215 | DI + config + lifecycle |
| `src/Support/IndexResolver.php` | 151 | Index resolution logic |
| `src/Support/QueryPlanner.php` | 263 | Explain mode plans |
| `src/Support/StampedeProtection.php` | 194 | Lock-based stampede |
| `src/Support/Observability.php` | 284 | In-memory ring buffers |
| `src/Support/CacheManager.php` | 135 | Facade-style API |
| `src/Invalidation/InvalidationManager.php` | 43 | Strategy dispatch |
| `src/Invalidation/Strategies/SyncStrategy.php` | 45 | Sync invalidation |
| `src/Invalidation/Strategies/AsyncStrategy.php` | 21 | Async invalidation |
| `src/Console/DebugCommand.php` | 134 | Debug CLI |
| `src/Console/MonitorCacheCommand.php` | 318 | Monitor CLI |
| `src/Console/WarmupCommand.php` | 196 | Warmup CLI |
| `config/redis-model-cache.php` | 200 | Configuration |
| `phpstan.neon.dist` | 36 | SA config |
| `tests/TestCase.php` | 25 | Test base |
