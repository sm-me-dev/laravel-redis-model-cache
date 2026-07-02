# RedisModelService.php Refactor Plan

**Project:** laravel-redis-model-cache  
**Target:** `src/RedisModelService.php`  
**Laravel Version:** 12  
**PHP Version:** 8.4+  
**Date:** 2026-07-02  

---

## Executive Summary

Refactor `RedisModelService.php` to eliminate OOM risks, ensure atomic Redis operations, and remove blocking commands while maintaining full backward compatibility with the `ModelCacheService` contract.

---

## Current State Analysis

| Method | Lines | Issue | Risk |
|--------|-------|-------|------|
| `where()` | 211-240 | Uses `hgetall()` → loads ALL records into PHP memory, filters in PHP | **HIGH** |
| `storeModel()` | 242-252 | No pipeline support, individual Redis calls | **MED** |
| `storeIndexes()` | 254-263 | No pipeline support | **MED** |
| `storeSorted()` | 265-274 | No pipeline support | **MED** |
| `storeMany()` | 189-197 | Iterates models sequentially, no atomicity | **HIGH** |
| `collectKeysByPattern()` | 485-520 | Has `scan_strategy` config but already throws if SCAN unavailable | **LOW** |

---

## Phase 1: Memory Safety — `where()` Refactor

### Objective
Replace `hgetall()` + PHP filtering with index-based set intersection (`SINTER`) + batch hydration.

### Implementation

```php
public function where(array $where, bool $hydrate = true, ?array $only = null): Collection
{
    // 1. Validate all query fields are indexed
    foreach (array_keys($where) as $field) {
        if (! in_array($field, $this->indexes, true)) {
            throw new InvalidArgumentException(
                "Field '{$field}' is not indexed. Available indexes: " . implode(', ', $this->indexes)
            );
        }
    }

    // 2. Build index keys for each where clause
    $indexKeys = [];
    foreach ($where as $field => $value) {
        $indexKeys[] = $this->indexKey($field, $value);
    }

    // 3. Get intersecting IDs via SINTER
    $ids = $indexKeys === [] 
        ? [] 
        : $this->redis->sinter(...$indexKeys);

    // 4. Apply $only filter if provided
    if ($only !== null && $only !== []) {
        $ids = array_values(array_intersect($ids, $only));
    }

    // 5. Batch hydrate
    return $ids === [] 
        ? collect() 
        : $this->hydrateIds($ids, $hydrate);
}
```

### Modified `hydrateIds()` Signature

```php
protected function hydrateIds(array $ids, bool $hydrate = true): Collection
{
    if ($ids === []) {
        return collect();
    }

    $pipeline = $this->redis->pipeline();

    foreach ($ids as $id) {
        $pipeline->hget($this->hashKey(), $id);
    }

    $results = $pipeline->execute();

    if (! $hydrate) {
        return collect($results)->filter()->keys()->values();
    }

    return collect($results)
        ->filter()
        ->map(fn (mixed $item): Model => $this->newModelFromCache($this->deserialize((string) $item)))
        ->values();
}
```

### Validation Rules
- All fields in `$where` **must** exist in `$this->indexes`
- Throw `InvalidArgumentException` if any field is not indexed
- Empty `$where` returns all records (current behavior preserved via `all()`)

---

## Phase 2: Atomicity — Pipeline Support

### Objective
Add optional `$pipeline` parameter to all store methods; wrap `storeMany()` in single pipeline.

### Changes

#### `storeModel()` — Add Pipeline Parameter
```php
protected function storeModel(Model $model, $pipeline = null): void
{
    $client = $pipeline ?? $this->redis;
    $key = (string) $model->getKey();
    $data = $model->getAttributes();

    $client->hset($this->hashKey(), $key, $this->serializeResult($data));

    $this->storeIndexes($model, $pipeline);
    $this->storeSorted($model, $pipeline);
}
```

#### `storeIndexes()` — Add Pipeline Parameter
```php
protected function storeIndexes(Model $model, $pipeline = null): void
{
    $client = $pipeline ?? $this->redis;
    
    foreach ($this->indexes as $field) {
        $value = $model->{$field};
        if ($value === null) continue;
        
        $client->sadd($this->indexKey($field, $value), (string) $model->getKey());
    }
}
```

#### `storeSorted()` — Add Pipeline Parameter
```php
protected function storeSorted(Model $model, $pipeline = null): void
{
    $client = $pipeline ?? $this->redis;
    
    foreach ($this->sorted as $field) {
        $value = $model->{$field};
        if ($value === null) continue;
        
        $score = is_numeric($value) ? (float) $value : (float) (strtotime((string) $value) ?: 0);
        $client->zadd($this->sortedKey($field), $score, (string) $model->getKey());
    }
}
```

#### `storeMany()` — Full Rewrite with Pipeline
```php
protected function storeMany(Collection $models): void
{
    if ($models->isEmpty()) {
        return;
    }

    $pipeline = $this->redis->pipeline();
    
    foreach ($models as $model) {
        $this->storeModel($model, $pipeline);
    }

    $pipeline->execute();
    $this->applyTTL($this->hashKey());
}
```

### Benefits
- **All-or-nothing**: All models stored or none (atomicity)
- **Performance**: Single round-trip for batch operations
- **Backward compatible**: Optional parameter defaults to direct Redis client

---

## Phase 3: Blocking Command Removal — `collectKeysByPattern()`

### Objective
Simplify to strict SCAN-only implementation; remove `scan_strategy` config check.

### Simplified Implementation
```php
/**
 * @return array<int, string>
 */
protected function collectKeysByPattern(string $pattern): array
{
    $count = (int) config('redis-model-cache.scan_count', 1000);
    $keys = [];

    if (is_a($this->redis, 'Predis\Client')) {
        $cursor = null;
        do {
            $chunk = $this->redis->scan($cursor, ['match' => $pattern, 'count' => $count]);
            if (is_array($chunk)) {
                $keys = array_merge($keys, $chunk);
            }
        } while ($cursor !== 0 && $cursor !== '0' && $cursor !== null);
        
        return array_values(array_unique($keys));
    }

    if (method_exists($this->redis, 'scan')) {
        $iterator = null;
        do {
            $chunk = $this->redis->scan($iterator, $pattern, $count);
            if (is_array($chunk)) {
                $keys = array_merge($keys, $chunk);
            }
        } while ($iterator !== 0 && $iterator !== '0' && $iterator !== null);
        
        return array_values(array_unique($keys));
    }

    throw new RuntimeException(
        'SCAN command is not available. The Redis client must support SCAN for production use.'
    );
}
```

### Config Change
- Remove `scan_strategy` from `config/redis-model-cache.php` (no longer needed)
- Keep `scan_count` for batch size tuning

---

## Phase 4: Code Quality & Compliance

| Check | Status | Action |
|-------|--------|--------|
| `declare(strict_types=1)` | ✅ Present | No change |
| PSR-12 | ✅ Mostly compliant | Run `vendor/bin/pint --dirty` after edits |
| Service-Action pattern | ✅ Used | Maintain |
| Constructor signature | ✅ Unchanged | Preserve |
| ModelCacheService contract | ✅ Compatible | No signature changes to public methods |

---

## Impact Analysis

### Public API (Unchanged)
All public methods retain identical signatures:
- `all(bool $hydrate = true, ?array $only = null): Collection`
- `where(array $where, bool $hydrate = true, ?array $only = null): Collection`
- `rememberAll(callable $callback, bool $hydrate = true, array $where = [], bool $refresh = false, ?array $only = null): Collection`
- `remember(callable $callback, bool $refresh = false, string|Expression|null $findBy = null, mixed $findValue = null, string $findOperator = '='): ?Model`
- `rememberIndex(string $field, string|int $value, callable $callback, bool $hydrate = true): Collection`
- `rememberCustom(string $name, callable $callback, bool $hydrate = true, ?string $sortBy = null, bool $refresh = false): Collection`
- `delete(int|string $id): void`
- `clear(): void`
- `clearAll(): void`
- `custom(string $name): Collection`
- `customWhere(array $names): Collection`
- `paginateSorted(string $field, int $page, int $perPage): Collection`
- `sorted(string $field, int $start, int $end): Collection`

### Internal Callers

| Caller | Impact | Notes |
|--------|--------|-------|
| `rememberAll()` → `where()` | ✅ Compatible | Uses same signature |
| `remember()` → `findInCache()` | ⚠️ Review | Uses `hgetall`; consider index optimization |
| `rememberIndex()` → `hydrateIds()` | ✅ Compatible | Already uses set intersection |
| `rememberCustom()` → `hydrateIds()` | ✅ Compatible | Already uses set intersection |
| `delete()` → `removeIndexes()`/`removeSorted()` | ✅ Unchanged | No pipeline needed for deletes |

### Test Coverage Needed

Verify `where()` with:
- [ ] Valid indexed fields
- [ ] Non-indexed fields (expects `InvalidArgumentException`)
- [ ] Empty results
- [ ] `$only` filter
- [ ] `$hydrate = false` (returns IDs only)
- [ ] Multiple where conditions (AND logic via SINTER)
- [ ] Performance with large datasets

---

## Clarifying Questions

### 1. `remember()` Method Optimization
Currently uses `hgetall()` + `findInCache()` for non-indexed lookups (L303-320). Should this also be optimized to use indexes when the `$findBy` field is indexed?

**Options:**
- A) Keep as-is (primary key lookups are fast enough)
- B) Add index-based fast path when `$findBy` field is indexed
- C) Deprecate in favor of `where()` / `rememberIndex()`

### 2. `findInCache()` Refactor
Used by `remember()` for exact-match lookups (L331-341). Should this be refactored to use index-based lookups when the `$findBy` field is indexed?

### 3. Backward Compatibility
Are there any external consumers calling protected methods (`storeModel()`, `storeIndexes()`, `storeSorted()`) directly? Codebase search shows internal-only usage.

### 4. Testing Scope
Should I also create/update tests, or is implementation-only refactor sufficient?

### 5. `customWhere()` Status
Already uses `sinter` + `hydrateIds` (L111-117) — follows the new pattern. No changes needed?

---

## Execution Order

| Phase | Files | Est. Lines | Risk | Dependencies |
|-------|-------|------------|------|--------------|
| 1 | `RedisModelService.php` | ~40 | HIGH | None |
| 2 | `RedisModelService.php` | ~35 | MED | Phase 1 (hydrateIds signature) |
| 3 | `RedisModelService.php`, `config/redis-model-cache.php` | ~30 | LOW | None |
| 4 | `RedisModelService.php` | ~0 | LOW | Phases 1-3 complete |

**Total:** ~105 lines modified in 1 primary file + config

---

## Verification Checklist

After implementation:

- [ ] `vendor/bin/pint --dirty` passes (PSR-12)
- [ ] `vendor/bin/phpstan analyse` passes (if configured)
- [ ] All existing tests pass
- [ ] New tests for `where()` validation pass
- [ ] Memory profile: `where()` no longer loads full hash
- [ ] Atomicity: `storeMany()` partial failure leaves no orphaned data
- [ ] `collectKeysByPattern()` throws on unsupported Redis clients
- [ ] No breaking changes to public API

---

## Rollback Plan

If issues arise:
1. Git revert to pre-refactor commit
2. Public API unchanged → zero consumer impact
3. Internal methods have optional pipeline params → backward compatible

---

## Approval Required

Please confirm:
1. Approach for Phases 1-4 ✅ / ❌ / Modify
2. Answers to clarifying questions (1-5)
3. Testing scope (implementation only vs. implementation + tests)
4. Go/no-go for implementation phase

---

*Generated by focused-fix + laravel-architect + senior-backend + performance-profiler skills*