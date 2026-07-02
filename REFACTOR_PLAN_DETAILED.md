# RedisModelService.php — Principal Staff Engineer Refactor Plan

**Project:** laravel-redis-model-cache  
**Target:** `src/RedisModelService.php`  
**Laravel:** 12 | **PHP:** 8.4+ | **Redis:** 7.2+ (SCAN mandatory)  
**Date:** 2026-07-02  
**Author:** Principal Staff Engineer / Senior Laravel Core Contributor  

---

## Executive Summary

Complete eradication of `hgetall()` from the codebase. Replace full-hash scans with **index-driven set operations** (`SINTER`/`SMEMBERS`/`ZRANGE`). Introduce **atomic pipeline-based writes**. Serialize **eager-loaded relations** natively to eliminate N+1 on hydration. All public `ModelCacheService` signatures preserved.

---

## Current Architecture Snapshot

| Method | Lines | Current Approach | Target Approach |
|--------|-------|------------------|-----------------|
| `where()` | 211-240 | `hgetall()` → PHP filter | `SINTER` indexes → `hydrateIds()` |
| `all()` | 199-210 | `hgetall()` | **Deprecate/redirect** → use indexes or throw |
| `remember()` | 303-320 | `hgetall()` → `findInCache()` | Index lookup or `InvalidArgumentException` |
| `findInCache()` | 331-341 | `hgetall()` loop | **Remove** — replaced by index path |
| `storeModel()` | 242-252 | Sync `hset` + indexes | Pipeline-aware, serializes relations |
| `storeIndexes()` | 254-263 | Sync `sadd` | Pipeline-aware |
| `storeSorted()` | 265-274 | Sync `zadd` | Pipeline-aware |
| `storeMany()` | 189-197 | Sequential loop | Single `pipeline()->execute()` |
| `collectKeysByPattern()` | 485-520 | Configurable strategy | **Strict SCAN-only**, throw if unavailable |
| `hydrateIds()` | 93-110 | Pipeline `hget` → deserialize | Pipeline `hget` → **restore relations** |

---

## Phase 1 — Memory Safety: `where()` + `hydrateIds()` Refactor

### 1.1 New `where()` Implementation

```php
public function where(array $where, bool $hydrate = true, ?array $only = null): Collection
{
    // 1. Validate ALL query fields are indexed
    foreach (array_keys($where) as $field) {
        if (! in_array($field, $this->indexes, true)) {
            throw new InvalidArgumentException(
                "Field '{$field}' is not indexed. Define it in \$indexes constructor arg. "
                . "Available: [" . implode(', ', $this->indexes) . "]"
            );
        }
    }

    // 2. Build index keys for each equality condition
    $indexKeys = [];
    foreach ($where as $field => $value) {
        $indexKeys[] = $this->indexKey($field, $value);
    }

    // 3. Set intersection = AND logic
    $ids = $indexKeys === [] ? [] : $this->redis->sinter(...$indexKeys);

    // 4. Optional $only filter (primary keys)
    if ($only !== null && $only !== []) {
        $ids = array_values(array_intersect($ids, $only));
    }

    // 5. Batch hydrate (now relation-aware)
    return $ids === [] ? collect() : $this->hydrateIds($ids, $hydrate);
}
```

### 1.2 `hydrateIds()` — Signature Change + Relation Restoration

```php
/**
 * @param array<int, string> $ids
 * @return Collection<int, Model>
 */
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
        ->map(function (string $payload): Model {
            $data = $this->deserialize($payload);
            return $this->hydrateModelFromPayload($data);
        })
        ->values();
}
```

### 1.3 New: `hydrateModelFromPayload()` — Core Hydration Logic

```php
/**
 * Reconstructs a Model from stored payload including eager-loaded relations.
 *
 * @param array{attributes: array, relations: array} $payload
 */
protected function hydrateModelFromPayload(array $payload): Model
{
    $model = (new $this->model_class)->newFromBuilder($payload['attributes'] ?? []);
    
    if (! empty($payload['relations'])) {
        $this->restoreRelations($model, $payload['relations']);
    }
    
    return $model;
}
```

---

## Phase 2 — Eager-Loaded Relations Serialization

### 2.1 Payload Structure (Stored in Redis Hash)

```php
$payload = [
    'attributes' => $model->getAttributes(),           // Base attributes
    'relations'  => $this->extractRelations($model),   // Recursive relation tree
];
```

### 2.2 `extractRelations()` — Deep Serialization

```php
/**
 * Recursively extracts eager-loaded relations into a serializable structure.
 *
 * @return array<string, array|null>  // relationName => serialized relation data
 */
protected function extractRelations(Model $model): array
{
    $relations = [];
    
    foreach ($model->getRelations() as $name => $relation) {
        if ($relation instanceof Collection) {
            // HasMany, MorphMany, BelongsToMany
            $relations[$name] = $relation->map(function (Model $related): array {
                return $this->serializeModel($related);
            })->toArray();
            
        } elseif ($relation instanceof Model) {
            // HasOne, BelongsTo, MorphOne, MorphTo
            $relations[$name] = $this->serializeModel($relation);
            
        } elseif ($relation === null) {
            // Explicitly loaded null relation (e.g., BelongsTo with no parent)
            $relations[$name] = null;
        }
        // Note: Unloaded relations are NOT in getRelations() — correctly omitted
    }
    
    return $relations;
}

/**
 * Serializes a single model (attributes + nested relations).
 *
 * @return array{class: string, attributes: array, relations: array}
 */
protected function serializeModel(Model $model): array
{
    return [
        'class'      => get_class($model),
        'attributes' => $model->getAttributes(),
        'relations'  => $this->extractRelations($model),  // Recursive
    ];
}
```

### 2.3 `restoreRelations()` — Deep Hydration

```php
/**
 * Restores eager-loaded relations onto a model instance.
 *
 * @param array<string, array|null> $relations  // Same structure as extractRelations()
 */
protected function restoreRelations(Model $model, array $relations): void
{
    foreach ($relations as $name => $relationData) {
        if ($relationData === null) {
            $model->setRelation($name, null);
            continue;
        }
        
        if (isset($relationData[0]['class'])) {
            // Collection relation (HasMany, MorphMany, BelongsToMany)
            $collection = collect($relationData)->map(function (array $item): Model {
                return $this->hydrateRelatedModel($item);
            });
            $model->setRelation($name, $collection);
            
        } else {
            // Single model relation (BelongsTo, HasOne, MorphOne, MorphTo)
            $model->setRelation($name, $this->hydrateRelatedModel($relationData));
        }
    }
}

/**
 * @param array{class: string, attributes: array, relations: array} $data
 */
protected function hydrateRelatedModel(array $data): Model
{
    $model = new $data['class'];
    $model->setRawAttributes($data['attributes'], true);
    
    if (! empty($data['relations'])) {
        $this->restoreRelations($model, $data['relations']);
    }
    
    return $model;
}
```

### 2.4 Updated `storeModel()` — Relation-Aware + Pipeline

```php
protected function storeModel(Model $model, $pipeline = null): void
{
    $client = $pipeline ?? $this->redis;
    $key    = (string) $model->getKey();
    
    // New: Structured payload with relations
    $payload = [
        'attributes' => $model->getAttributes(),
        'relations'  => $this->extractRelations($model),
    ];
    
    $client->hset($this->hashKey(), $key, $this->serializeResult($payload));
    
    $this->storeIndexes($model, $pipeline);
    $this->storeSorted($model, $pipeline);
}
```

### 2.5 Updated `removeIndexes()` — Read Attributes from New Payload

```php
protected function removeIndexes(int|string $id, array $oldData): void
{
    // oldData now has structure: ['attributes' => [...], 'relations' => [...]]
    $attributes = $oldData['attributes'] ?? $oldData;  // Backward compat for old cache entries
    
    foreach ($this->indexes as $field) {
        if (! array_key_exists($field, $attributes)) {
            continue;
        }
        $this->redis->srem($this->indexKey($field, $attributes[$field]), (string) $id);
    }
}
```

> **Migration Note:** Existing cache entries lack `relations` key. The null-coalesce in `removeIndexes()` handles this gracefully. On next write, relations are stored.

---

## Phase 3 — Atomicity: Pipeline-Based Writes

### 3.1 `storeIndexes()` — Pipeline-Aware

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

### 3.2 `storeSorted()` — Pipeline-Aware

```php
protected function storeSorted(Model $model, $pipeline = null): void
{
    $client = $pipeline ?? $this->redis;
    
    foreach ($this->sorted as $field) {
        $value = $model->{$field};
        if ($value === null) continue;
        
        $score = is_numeric($value) 
            ? (float) $value 
            : (float) (strtotime((string) $value) ?: 0);
        
        $client->zadd($this->sortedKey($field), $score, (string) $model->getKey());
    }
}
```

### 3.3 `storeMany()` — Single Pipeline Execution

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

---

## Phase 4 — Eradicate `hgetall()`: `remember()` + `findInCache()`

### 4.1 New `remember()` — Index-First, No Full Scans

```php
public function remember(
    callable $callback,
    bool $refresh = false,
    string|Expression|null $findBy = null,
    mixed $findValue = null,
    string $findOperator = '='
): ?Model
{
    // Fast path: if findBy is indexed AND not refresh, try index lookup
    if (! $refresh && $findBy !== null && $this->isIndexed($findBy)) {
        $fieldName = $this->resolveFieldName($findBy);
        $result = $this->findByIndex($fieldName, $findValue, $findOperator);
        
        if ($result !== null) {
            return $result;
        }
    }
    
    // Cache miss or non-indexed lookup: execute callback
    $models = collect($callback());
    
    if ($models->isEmpty()) {
        return null;
    }
    
    $this->storeMany($models);
    
    // Post-store lookup (guaranteed to hit index if indexed)
    if ($findBy !== null && $this->isIndexed($findBy)) {
        $fieldName = $this->resolveFieldName($findBy);
        return $this->findByIndex($fieldName, $findValue, $findOperator);
    }
    
    // Non-indexed findBy: THROW per requirement
    throw new InvalidArgumentException(
        "Field '{$findBy}' is not indexed. Cannot perform lookup without index. "
        . "Add to \$indexes or use where()/rememberIndex()."
    );
}
```

### 4.2 New: `isIndexed()` + `resolveFieldName()`

```php
protected function isIndexed(string|Expression $field): bool
{
    if ($field instanceof Expression) {
        return false;  // Expressions cannot be indexed
    }
    return in_array($field, $this->indexes, true);
}

protected function resolveFieldName(string|Expression $field): string
{
    if ($field instanceof Expression) {
        // Expression handling preserved from original modelMatches()
        $grammar = (new $this->model_class)->newQuery()->getGrammar();
        $value = $field->getValue($grammar);
        preg_match_all('/(\w+)/', (string) $value, $matches);
        $fields = array_intersect($matches[1], array_keys((new $this->model_class)->getAttributes()));
        return $fields[0] ?? '';
    }
    return $field;
}
```

### 4.3 New: `findByIndex()` — Index-Driven Single Model Lookup

```php
protected function findByIndex(string $field, mixed $value, string $operator): ?Model
{
    // Only equality supported for index lookups
    if ($operator !== '=') {
        return null;  // Fall through to exception in remember()
    }
    
    $key = $this->indexKey($field, $value);
    $ids = $this->redis->smembers($key);
    
    if ($ids === []) {
        return null;
    }
    
    // Take first match (should be unique for PK lookups)
    $models = $this->hydrateIds($ids, true);
    
    return $models->first() ?? null;
}
```

### 4.4 `findInCache()` — **REMOVED**

Delete entirely. Replaced by `findByIndex()` + `remember()` logic.

---

## Phase 5 — `all()` Method: Deprecation Strategy

### 5.1 Option A: Throw (Strict) — Recommended

```php
public function all(bool $hydrate = true, ?array $only = null): Collection
{
    throw new BadMethodCallException(
        'all() is disabled. Use where() with indexed fields, rememberIndex(), or customWhere(). '
        . 'Full hash scans are prohibited for memory safety.'
    );
}
```

### 5.2 Option B: Index-Based (If a "default index" exists)

If the model has a designated default index (e.g., `id`), we could redirect. But per requirements, **no full scans allowed**. Option A is correct.

---

## Phase 6 — Blocking Command Removal: `collectKeysByPattern()`

### 6.1 Strict SCAN-Only Implementation

```php
/**
 * @return array<int, string>
 */
protected function collectKeysByPattern(string $pattern): array
{
    $count = (int) config('redis-model-cache.scan_count', 1000);
    $keys  = [];

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
        'SCAN command is not available. The Redis client must support SCAN for production use. '
        'Ensure phpredis extension is installed or use Predis.'
    );
}
```

### 6.2 Config Cleanup

Remove `scan_strategy` from `config/redis-model-cache.php` — no longer used.

---

## Phase 7 — Code Quality & Compliance

| Check | Action |
|-------|--------|
| `declare(strict_types=1)` | ✅ Already present |
| PSR-12 | Run `vendor/bin/pint --dirty` after edits |
| PHPDoc | Add `@param`/`@return` to new private methods |
| Type Hints | All new methods fully typed |
| Imports | Add `BadMethodCallException`, `Collection` (if needed) |
| Constructor | **Unchanged** — zero signature changes |

---

## Impact Matrix

| Public Method | Signature Changed? | Behavior Changed? | Notes |
|---------------|-------------------|-------------------|-------|
| `where()` | No | **Yes** — now requires indexed fields, uses SINTER | Core fix |
| `all()` | No | **Yes** — throws `BadMethodCallException` | Intentional |
| `remember()` | No | **Yes** — index-only, throws if not indexed | Core fix |
| `rememberIndex()` | No | No | Already index-based |
| `rememberCustom()` | No | No | Already index-based |
| `customWhere()` | No | No | Already SINTER-based |
| `paginateSorted()` | No | No | Delegates to `sorted()` |
| `sorted()` | No | No | Delegates to `hydrateIds()` |
| `delete()` | No | No | Uses `removeIndexes()` (compat handled) |
| `clear()` / `clearAll()` | No | No | Uses `collectKeysByPattern()` (strict SCAN) |
| `custom()` | No | No | Delegates to `hydrateIds()` |

---

## New Private Methods Added

| Method | Purpose |
|--------|---------|
| `hydrateModelFromPayload(array)` | Core hydration from new payload structure |
| `extractRelations(Model)` | Deep serialization of eager-loaded relations |
| `serializeModel(Model)` | Single model → serializable array |
| `restoreRelations(Model, array)` | Deep hydration of relations onto model |
| `hydrateRelatedModel(array)` | Hydrate nested related model |
| `isIndexed(string\|Expression)` | Check if field has index |
| `resolveFieldName(string\|Expression)` | Normalize field name from Expression |
| `findByIndex(string, mixed, string)` | Index-driven single-model lookup |

---

## Backward Compatibility Handling

1. **Existing Cache Entries:** Lack `relations` key. `hydrateModelFromPayload()` handles missing key gracefully (empty relations).
2. **`removeIndexes()`:** Reads `$oldData['attributes'] ?? $oldData` for old/new format compatibility.
3. **No Migration Needed:** On next write, relations are stored. Old entries work without relations.

---

## Testing Requirements (Implementation-Only Scope)

Since testing is out of scope, these are **manual verification checkpoints**:

| Scenario | Expected |
|----------|----------|
| `where(['indexed_field' => 'value'])` | Returns Collection via SINTER |
| `where(['non_indexed' => 'value'])` | Throws `InvalidArgumentException` |
| `where([], hydrate: false)` | Returns Collection of IDs |
| `remember($cb, findBy: 'indexed', findValue: 'x')` | Returns Model via index |
| `remember($cb, findBy: 'non_indexed', ...)` | Throws `InvalidArgumentException` |
| `storeMany(modelsWithRelations)` | Single pipeline, relations persisted |
| `hydrateIds(ids)` | Models have `getRelations()` populated |
| `collectKeysByPattern('prefix:*')` | Returns keys via SCAN only |
| Non-SCAN Redis client | Throws `RuntimeException` |

---

## Execution Sequence

```
Phase 1: where() + hydrateIds() + hydrateModelFromPayload()
    │
    ├─ Phase 2: extractRelations() + serializeModel() + restoreRelations() + hydrateRelatedModel()
    │           └─ Update storeModel() + removeIndexes()
    │
    ├─ Phase 3: storeIndexes() + storeSorted() + storeMany() (pipeline)
    │
    ├─ Phase 4: remember() + isIndexed() + resolveFieldName() + findByIndex()
    │           └─ DELETE findInCache()
    │
    ├─ Phase 5: all() → throw BadMethodCallException
    │
    ├─ Phase 6: collectKeysByPattern() strict SCAN + config cleanup
    │
    └─ Phase 7: pint, static analysis, final review
```

---

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Breaking existing `where()` calls with non-indexed fields | HIGH | HIGH | Document migration: "Add field to `$indexes`" |
| Relation serialization breaks for complex types (MorphTo) | MED | MED | Test MorphTo explicitly; store class in payload |
| Pipeline atomicity: partial failure leaves orphaned indexes | LOW | HIGH | Acceptable — indexes are sets, TTL cleans up |
| `all()` callers break | MED | MED | Communicate: "Use `where()` with indexed field or `rememberIndex()`" |
| Memory spike during `hydrateIds()` for huge result sets | LOW | MED | Caller controls via `where()` specificity |

---

## Approval Gate

**Required before implementation:**

1. ✅ `where()` → `InvalidArgumentException` for non-indexed fields
2. ✅ `all()` → `BadMethodCallException` (no full scans)
3. ✅ `remember()` → Index-only, throws if not indexed
4. ✅ Eager-loaded relations: `extractRelations()` + `restoreRelations()` design
5. ✅ Pipeline atomicity in `storeMany()`
6. ✅ Strict SCAN-only in `collectKeysByPattern()`
7. ❓ **One decision:** Should `where()` support operators beyond `=` (e.g., `>`, `<`, `LIKE`)?
   - Current `matchesWhere()` supports `ModelMatchStrategy` with operators
   - Index-based `SINTER` only supports equality
   - **Recommendation:** Keep `where()` equality-only; document that operators require custom indexes or full scan (prohibited)

---

**Ready for implementation upon approval.**