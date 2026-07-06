# Cache Invalidation

Invalidation is deterministic — every cleanup step is explicit. There is no TTL-based "eventually consistent" cleanup for index/sorted-set entries.

## Event Flow

```
Model saved
  → bootHasRedisModelCache::saved listener
    → markRedisModelCacheProcessing (re-entry guard)
    → resolveRedisModelCacheService()->store($model)
    → InvalidationManager::handle('saved', $model)
      → SyncStrategy: bustVersion (if versioned mode)
    → touchRedisModelCacheParents($model)
    → unmarkRedisModelCacheProcessing

Model deleted
  → bootHasRedisModelCache::deleted listener
    → markRedisModelCacheProcessing (re-entry guard)
    → InvalidationManager::handle('deleted', $model)
      → SyncStrategy:
        → service->delete($modelId)     // HDEL + SREM × N + ZREM × N
        → service->removeCustomIndexes() // SREM × N
        → bustVersion (if versioned)
    → markRedisModelCacheDeletedInCycle
    → touchRedisModelCacheParents($model)
    → unmarkRedisModelCacheProcessing

Model forceDeleted
  → bootHasRedisModelCache::forceDeleted listener
    → Same as deleted flow
    → markRedisModelCacheDeletedInCycle
    → Prevents subsequent 'saved' event from re-caching
```

## Sync Strategy (default)

Executes Redis operations immediately in the same request cycle.

### On save:
- `service->store($model)` → HSET + SADD + ZADD + stale SREM
- If versioned: `HINCRBY meta version 1`

### On delete:
- `service->delete($id)` → HGET(old data) + HDEL + SREM(old indexes) + ZREM
- `service->removeCustomIndexes($id)` → SREM from all custom index sets
- If versioned: `HINCRBY meta version 1`

## Async Strategy

Dispatches `InvalidateModelCacheJob` to the configured queue. The job resolves the service and applies the sync strategy in the queue worker process.

Use when:
- Model writes are high-throughput and you want to offload Redis writes from the web request
- The slight delay between model save and cache update is acceptable

```php
'invalidation' => [
    'strategy' => 'async',
    'queue' => 'cache-invalidation',
],
```

## Versioned Invalidation

When `versioned` is `true`, every save/delete increments a version counter in the meta hash:

```
HINCRBY {users}:meta version 1
```

External systems can poll this counter to detect changes. Typical use cases:

- WebSocket broadcasts: check version, push delta to connected clients
- Read replicas: compare version with local cache, trigger re-fetch
- Search index updates: batch process models that changed since last poll

```php
$version = $redis->hget('{users}:meta', 'version');
```

## Re-entry Guard

The trait uses `$redisModelCacheProcessing` to prevent infinite loops. When a model is saved, the trait marks it as "processing". If the model is saved again during the same cycle (e.g., by a parent touch), the re-entry is ignored.

```
saved → mark(id=1) → storeModel → touchParents → saved(id=1) → isProcessing? → skip
```

## Delete-in-Cycle Guard

`forceDelete` fires three events in sequence: `forceDeleted` → `deleted` → `saved`. The `saved` event would normally re-cache the just-deleted model. The trait marks the model as "deleted in cycle" on `forceDeleted`/`deleted`, and the subsequent `saved` event checks this flag:

```
forceDeleted → markDeleted(id=1) → ...
 deleted → isProcessing? → skip (already marked)
 saved → isDeletedInCycle(id=1)? → skip
```

## Parent Touches

When a model is saved or deleted, the trait checks `redisModelCacheTouches()` for parent relations to update:

```php
class Post extends Model
{
    use HasRedisModelCache;

    protected static function redisModelCacheTouches(): array
    {
        return ['author'];  // BelongsTo User
    }
}
```

On Post save: `$post->author` is fetched, and if the author uses `HasRedisModelCache`, its cache entry is refreshed. Supports both single relations (`BelongsTo`, `HasOne`) and collection relations (`HasMany`, `BelongsToMany`).

## Stale Index Cleanup on Attribute Change

When an indexed attribute changes, the old index entry must be removed and the new one added. This happens atomically in both the Lua and pipeline paths:

```
Before: {users}:index:status:active → contains '42'
After store where status changed 'active' → 'inactive':
  → SREM {users}:index:status:active '42'
  → SADD {users}:index:status:inactive '42'
```

The stale index detection reads the old hash data via HGET (or batched HMGET in `storeMany()`), determines which index entries changed, and queues SREM commands.

For `updateAttribute()`/`updateAttributes()`, the same logic applies via `computeStaleIndexKeysFromData()`.

## Invalidation Edge Cases

### Model deleted while cache is stale

`delete()` always reads old data from Redis before removing it. If the model was never cached (HGET returns false), the method returns early. If the model was cached but stale, the index entries are still removed deterministically.

### Concurrent delete and save

If process A deletes a model while process B saves it, the final state depends on operation order:
1. A deletes → HDEL + SREM + ZREM
2. B saves → HSET + SADD + ZADD
3. Result: model is in cache

Or:
1. B saves → HSET + SADD + ZADD
2. A deletes → HDEL + SREM + ZREM
3. Result: model is removed

Both outcomes are deterministic per operation order. The trait's re-entry guard prevents self-conflict but does not implement distributed locking for cross-process coordination.

### TTL expiry vs invalidation

TTL is secondary to deterministic invalidation. TTL exists for memory reclamation (Redis eviction), not for correctness. Unless the cache is explicitly invalidated by a model lifecycle event, stale data will be served until TTL expiry + grace period (SWR).

## Invalidation Manager

The `InvalidationManager` is the entry point for all invalidation operations:

```php
$manager = new InvalidationManager(
    service: $service,
    strategy: 'sync',      // 'sync' | 'async'
    versioned: false,
    queue: 'default',
);

$manager->handle('deleted', $model);
```

The `InvalidationContext` captures the full event state:

```php
new InvalidationContext(
    modelClass: User::class,
    modelId: 42,
    event: 'deleted',       // 'saved' | 'deleted'
    attributes: [...],      // Current model attributes
    original: [...],        // Pre-change attributes
    timestamp: 1234567890.0,
);
```

## Cache Clearing

Two clearing methods exist:

| Method | Scope | Use Case |
|---|---|---|
| `clear()` | Hash + indexes + sorted + custom for this model | Selective rebuild of one model type |
| `clearAll()` | ALL keys matching prefix pattern | Full flush (e.g., schema migration) |

Both use `SCAN` (cursor-based, production-safe) to discover keys. The `KEYS` command is never used.
