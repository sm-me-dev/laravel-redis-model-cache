# Query Limitations

This package replaces Eloquent's query builder with Redis set operations. Understanding the limitations is critical for production use.

## Supported vs Unsupported

| Operation | Status | Why |
|---|---|---|
| `where(field = value)` single index | ✅ Supported | `SMEMBERS` — O(N) on set cardinality |
| `where(field1 = a, field2 = b)` multi-index | ✅ Supported | `SINTER` — O(N1 + N2 + intersection) |
| `whereIn(field, [a, b])` single value | ✅ Supported | `SMEMBERS` |
| `whereIn(field, [a, b, c])` multi-value | ✅ Supported | `SUNION` — O(N1 + N2 + N3) |
| `whereBetween(field, min, max)` sorted | ✅ Supported | `ZRANGEBYSCORE` — O(log N + M) |
| `orWhere()` combining result sets | ✅ Supported | `SINTER` + `array_merge` — two round trips |
| `find(id)` | ✅ Supported | `HGET` — O(1) |
| `first(where)` | ✅ Supported | `SMEMBERS/SINTER` + `HGET` first match |
| `count(where)` single index | ✅ Supported | `SCARD` — O(1) |
| `count(where)` multi-index | ✅ Supported | `SINTER` + `count` |
| `exists(where)` single index | ✅ Supported | `EXISTS` — O(1) |
| `exists(where)` multi-index | ✅ Supported | `SINTER` + check |
| `selective(fields, where)` | ✅ Supported | `SINTER` + `HMGET` — batch round trip |
| `pluck(attrs, where)` | ✅ Supported | `SINTER` + `HMGET` — batch round trip |
| `sorted(field, start, end)` | ✅ Supported | `ZREVRANGE` — O(log N + M) |
| `paginateSorted(field, page, perPage)` | ✅ Supported | `ZREVRANGE` with offset calc |
| `custom(name)` | ✅ Supported | `SMEMBERS` |
| `customWhere([a, b])` | ✅ Supported | `SINTER` |

| Operation | Status | Reason |
|---|---|---|
| `where(field != value)` — not-equal | ❌ Not Supported | Redis sets have no complement operator. Would require full set diff. |
| `where(field LIKE '%value%')` — contains | ❌ Not Supported | Would need full set scan + client-side filtering. Use a separate inverted index. |
| `where(field > value)` — greater-than | ❌ Not Supported (non-sorted) | Add the field to `$sorted` and use `whereBetween()` with a high max. |
| `where(field < value)` — less-than | ❌ Not Supported (non-sorted) | Add the field to `$sorted`. |
| `where(field IS NULL)` | ❌ Not Supported | Null values are not indexed (skipped in `storeIndexes()`). |
| `where(field IS NOT NULL)` | ❌ Not Supported | Complement of null — see not-equal. |
| `orderBy`, `groupBy`, `having`, `join` | ❌ Not Supported | Eloquent builder operations. These must run via the DB, not the cache. |
| `all()` | ❌ Disabled | Full hash scans are prohibited for memory safety. Use `where()` with at least one indexed field. |
| `rememberAll()` with empty `$where` | ❌ Throws | Same reason as `all()` — unindexed cache fetches are blocked. |
| Sub-second consistency between write and read | ⚠️ Best-effort | Pipeline operations are atomic within a single connection, but replication lag in Redis cluster may cause stale reads during failover. |

## How to Work Around Limitations

### Not-equal queries

Not supported because Redis sets have no built-in complement. To filter out values:

1. Fetch the superset via index lookup
2. Filter client-side:

```php
$allActive = $cache->where(['status' => 'active']);
$filtered = $allActive->reject(fn ($user) => $user->role_id === 1);
```

### Range queries on non-sorted fields

Add the field to `$sorted` in the constructor:

```php
$cache = app(RedisModelService::class, [
    'model_class' => User::class,
    'indexes' => ['status'],
    'sorted' => ['created_at', 'login_count'],  // range query support
]);
```

Then use `whereBetween()`:

```php
$recent = $cache->whereBetween('created_at', strtotime('-7 days'), time());
$highScorers = $cache->whereBetween('login_count', 100, 99999);
```

### Combined equality + range queries

For `WHERE status = 'active' AND created_at BETWEEN X AND Y`:

1. Fetch IDs matching the indexed field via `where()`
2. Fetch IDs from the sorted field via `whereBetween()`
3. Intersect client-side:

```php
$activeIds = $cache->where(['status' => 'active'], hydrate: false);
$recentIds = $cache->whereBetween('created_at', $min, $max, hydrate: false);
$combined = $activeIds->intersect($recentIds);
$models = $cache->find(...$combined);  // via HGET on each
```

This is a two-round-trip approach. For frequently used combined queries, create a custom index set.

### `LIKE` / full-text search

Not supported. Use a dedicated search engine (Meilisearch, Typesense, Elasticsearch) or maintain a separate Redis inverted index manually using `rememberCustom()`.

## Complexity Guarantees

| Method | Redis Time | Network Round Trips |
|---|---|---|
| `find(id)` | O(1) | 1 |
| `where()` single index | O(N) on set size | 1 + HMGET batch |
| `where()` multi-index | O(N1 + N2 + ...) | 1 + HMGET batch |
| `whereBetween()` | O(log N + M) | 1 + HMGET batch |
| `whereIn()` | O(N1 + N2 + ...) | 1 + HMGET batch |
| `first()` | O(N) worst-case SMEMBERS | 2 |
| `count()` single index | O(1) | 1 |
| `exists()` single index | O(1) | 1 |
| `store()` | O(K) where K = # indexes + sorted | 1 (Lua) or pipeline |
| `storeMany(N)` | O(N × K) | 2 (HMGET + pipeline) |
| `delete()` | O(K) | 1 |
| `updateAttribute()` | O(K) | 1 (HGET) + pipeline |
| `clearAll()` | O(keys) via SCAN | SCAN rounds + DEL |

## Memory Sizing Guide

Each cached model consumes:

| Component | Size | Note |
|---|---|---|
| Hash field value | ~same as JSON + (compression overhead) | Compressed with gzip/zstd if enabled |
| Index set member | ~8-16 bytes per ID | One entry per indexed field per model |
| Sorted set member | ~8-16 bytes + 8 bytes (score) | One entry per sorted field per model |
| Custom index set member | ~8-16 bytes per ID | One entry per custom index per model |

**Example**: 10,000 users, 2 indexes, 1 sorted field:
- Hash: ~10 MB (assuming 1 KB JSON per model)
- Index sets: ~300 KB (10,000 × 3 fields × 10 bytes)
- Total: ~10.3 MB + Redis overhead (~20-30%)

With compression (gzip, level 6): ~5-7 MB for the hash.
