# Architecture — Redis Model Cache v2.1

## System Boundaries (Frozen)

### 1. Query Layer
**File:** `src/RedisModelService.php`
**Contract:** `src/Contracts/ModelCacheService.php`

Read operations that resolve model IDs from indexes then hydrate from the hash store.

| Method | Redis Ops | Strategy |
|---|---|---|
| `where(array $where)` | SINTER | Index intersection (AND) |
| `whereIn(string $field, array $values)` | SMEMBERS / SUNION | Index union (OR) |
| `whereBetween(string $field, $min, $max)` | ZRANGEBYSCORE | Sorted-set range |
| `orWhere(array $where, array $baseIds)` | SINTER + array_merge | Set intersection + PHP union |
| `pluck(array $attributes, array $where)` | SINTER + pipeline HGET | Index scan + partial hydrate |
| `sorted(string $field, int $start, int $end)` | ZREVRANGE | Sorted-set pagination |
| `paginateSorted(string $field, int $page, int $perPage)` | ZREVRANGE | Offset-based sorted paging |
| `custom(string $name)` | SMEMBERS | Direct set membership |
| `customWhere(array $names)` | SINTER | Custom-set intersection |
| `all()` | **DISABLED** (throws) | Memory safety — full hash scan prohibited |

**Invariants:**
- Every `$where` field MUST be declared in `$indexes` or an `InvalidArgumentException` is thrown.
- `whereIn()` requires a non-empty values array.
- `whereBetween()` requires the field in `$sorted`.
- `all()` is permanently disabled to prevent unbounded memory usage.
- Every read method dispatches `CacheHit` and `QueryExecuted` events when metrics are enabled.
- All read methods accept `$only` (primary-key whitelist) for post-filtering.

### 2. Index Layer
**File:** `src/RedisModelService.php` (lines 1609–1699)

Index-population operations that check existence before executing callbacks.

| Method | Redis Ops | Purpose |
|---|---|---|
| `rememberIndex(string $field, $value, callable)` | EXISTS + SMEMBERS + SADD | Index-first warmup |
| `rememberCustom(string $name, callable)` | EXISTS + SMEMBERS/SADD/ZADD | Custom index warmup |
| `where(array $where)` | SINTER (see Query) | Index-set read |

**Invariants:**
- `rememberIndex()` writes index keys via `SADD` AFTER storing each model.
- `rememberCustom()` supports optional `$sortBy` parameter which promotes the set to a sorted set.
- Index keys expire alongside the hash (`applyTTL`).
- All index writes happen inside the same pipeline (or Lua script) as the hash write.

### 3. Cache Layer
**Files:** `src/RedisModelService.php`, `src/RedisBaseService.php`, `src/RedisHelperService.php`
**Contracts:** `ModelCacheService`, `HashCacheService`

Storage, serialization, compression, TTL, stampede protection, and SWR.

| Method | Purpose |
|---|---|
| `rememberAll(callable, $where, $stampede, $swr)` | Full-set cache with stampede/SWR |
| `remember(callable, $findBy, $findValue)` | Single-model cache with index-fast-path |
| `store(Model $model)` | Single model write |
| `storeMany(Collection $models)` | Batch write (bulk HMGET + pipeline) |
| `RedisHelperService::rememberSet(...)` | Generic hash-set cache |

**Invariants:**
- Serialization format: `{attributes: {}, relations: {}}` (JSON, optionally compressed).
- Compression auto-detects format by magic bytes (`\x1f\x8b` = gzip, `\x28\xb5\x2f\xfd` = zstd, `\x04\x22\x4d\x18` = lz4).
- Batch writes use a single `HMGET` round-trip to fetch old data, then one pipeline for all writes.
- TTL is applied to EVERY key written (hash, index, sorted, custom) — no key is written without an expire.
- Stampede lock key: `{cacheKey}:lock` with CAS release via Lua.
- SWR grace period (default 300s) dispatches `RevalidateCacheJob` on stale-but-valid reads.

### 4. Invalidation Layer
**Files:** `src/RedisModelService.php`, `src/Concerns/HasRedisModelCache.php`

Eviction, index cleanup, and parent-touch propagation.

| Method | Scope |
|---|---|
| `delete(int\|string $id)` | Single model: HDEL + SREM indexes + ZREM sorted |
| `clear()` | All keys for model: hash + meta + index:* + sorted + custom |
| `clearAll()` | Wildcard `{prefix}:*` = SCAN + DEL |
| `updateAttribute($id, $attr, $value)` | In-place attribute update with index migration |
| `updateAttributes($id, array $attrs)` | Batch in-place attribute update |
| HasRedisModelCache trait: `saved`/`deleted`/`forceDeleted` | Auto-cache lifecycle via Eloquent events |
| HasRedisModelCache trait: `touchRedisModelCacheParents` | Parent-cache propagation when configured |

**Invariants:**
- `delete()` reads old data to compute stale index keys BEFORE removing the hash entry.
- `clearAll()` uses SCAN (cursor-based), never KEYS.
- HasRedisModelCache uses `$redisModelCacheProcessing` to prevent recursive event loops.
- HasRedisModelCache uses `$redisModelCacheDeletedInCycle` to prevent saved-event re-caching after forceDelete.

---

## Redis Key Schema

All keys use `{table}` as a Redis cluster hash tag.

```
{table}:hash              → HASH     Model data {id → serialized JSON}
{table}:meta              → HASH     Metadata {cached_at → timestamp}
{table}:index:{field}:{v} → SET      Index members {id, ...}
{table}:sorted:{field}    → ZSET     Sorted index {id → score}
{table}:custom:{name}     → SET      Custom index members {id, ...}
{table}:custom:{name}:sorted:{field} → ZSET  Custom sorted index
{table}:hash:lock         → STRING   Stampede lock (SET NX EX)
```

With multi-tenant enabled:
```
{tenant:{tenantId}:{table}}:hash
{tenant:{tenantId}:{table}}:index:status:active
```

---

## Data Flow Diagram

```
┌─ Application ──────────────────────────────────────────────┐
│  Eloquent Model (uses HasRedisModelCache trait)             │
│    saved() ──→ RedisModelService::store() ──→ Redis HASH   │
│    deleted() ─→ RedisModelService::delete() ─→ Redis DEL   │
│    touches ───→ parent store()                             │
└────────────────────────────────────────────────────────────┘

┌─ Query Flow ───────────────────────────────────────────────┐
│                                                             │
│  where(['status'=>'active'])                                │
│    │                                                        │
│    ├─ SINTER {users}:index:status:active                    │
│    │    Returns: [id1, id2, ...]                            │
│    │                                                        │
│    └─ Pipeline HGET {users}:hash × N                       │
│         → deserialize + hydrate models                      │
│         → restore relations                                 │
│                                                             │
│  rememberAll(callback, $stampede=true, $swr=true)           │
│    │                                                        │
│    ├─ EXISTS {users}:hash?                                  │
│    │   ├─ YES → check stale status (SWR)                    │
│    │   │   ├─ within_grace → serve stale + dispatch job     │
│    │   │   └─ beyond_grace → fall through to rebuild        │
│    │   ├─ NO → acquire stampede lock                        │
│    │   │   ├─ acquired → execute callback → storeMany       │
│    │   │   └─ waited → re-check EXISTS                      │
│    │   └─ REFRESH → skip checks, execute callback           │
│    └─ where() → return hydrated models                      │
│                                                             │
└────────────────────────────────────────────────────────────┘

┌─ Write Flow ────────────────────────────────────────────────┐
│                                                              │
│  storeMany(Collection $models)                               │
│    │                                                         │
│    ├─ HMGET {users}:hash [id1, id2, ...] (old data)         │
│    ├─ Compute stale index keys per model                    │
│    ├─ Pipeline:                                              │
│    │   HSET {users}:hash id → {attrs+relations}             │
│    │   SREM old-index-key id (× stale)                     │
│    │   SADD new-index-key id (× indexes)                   │
│    │   ZADD {users}:sorted:created_at score id (× sorted)  │
│    │   EXPIRE {users}:hash TTL                              │
│    │   EXPIRE each index key TTL                             │
│    └─ HSET {users}:meta cached_at → now                     │
│                                                              │
│  (Lua atomic path: single EVALSHA with KEYS + ARGV)         │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

---

## SOLID Assessment

| Principle | Status | Details |
|---|---|---|
| **SRP** | ❌ Violation | `RedisModelService` (2084 lines) handles querying, indexing, caching, invalidation, serialization, compression, observability, debug, explain |
| **OCP** | ✅ Good | `ModelMatchStrategy`, `RedisConnectionResolver` interfaces enable extension |
| **LSP** | ✅ Clean | Implementations (`DefaultConnectionResolver`, `DefaultModelMatchStrategy`) satisfy contracts |
| **ISP** | ✅ Good | Contracts are granular (`ModelCacheService`, `HashCacheService`, `RedisConnectionResolver`, `ModelMatchStrategy`, `TenantResolverInterface`) |
| **DIP** | ⚠️ Partial | Constructor depends on interfaces, but `HasRedisModelCache` trait uses `app(RedisModelService::class)` (service locator) |

## Key Coupling Points

1. **`RedisModelService` extends `RedisBaseService`** — inheritance couples querying with compression, serialization, Lua execution. All four layers share the same class.
2. **`config()` calls in hot paths** — Observability, compression, and Lua settings read from config on every call.
3. **`HasRedisModelCache` trait** — resolves `RedisModelService` fresh every call via `app()`, creating many short-lived service instances.
4. **Constructor coupling** — optional `$connection` parameter internally replaces the injected `RedisConnectionResolver`.
