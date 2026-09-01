# v1.1.0 — Memory-Safe Redis Model Cache

## Breaking Changes

| Change | Before | After |
|--------|--------|-------|
| `all()` | Returns all cached records | **Throws `BadMethodCallException`** — use `where()` with indexed fields |
| `where()` | Accepted any field | **Requires indexed fields** — throws `InvalidArgumentException` otherwise |
| `remember()` | Any `findBy` field | **Requires indexed `findBy`** — throws `InvalidArgumentException` otherwise |
| `rememberAll()` empty `where` on warm cache | Performed full hash scan | **Throws `BadMethodCallException`** — provide indexed `$where` clause |

## New Features

- **Index-Driven Queries** — `where()` uses `SINTER` for fast set intersections; eliminates OOM risk from full hash scans.
- **Pipeline Atomicity** — `storeMany()` executes exactly one pipeline call (single round-trip to Redis).
- **Eager-Relation Hydration** — HasMany, BelongsTo, HasOne relations are serialized on store and restored on hydrate without extra queries.
- **SCAN Safety** — `collectKeysByPattern()` returns uniqued keys; correct Predis tuple unpacking (`$result[0]` = cursor, `$result[1]` = keys); no `KEYS` fallback.

## Requirements

- PHP `^8.4`
- Laravel `^12.0`

## Migration Guide

```diff
- $all = $cacheService->all();
+ $active = $cacheService->where(['status' => 'active']);

- $records = $cacheService->where(['email' => 'test@example.com']);
+ $records = $cacheService->where(['email' => 'test@example.com']);
+ // Throws — add 'email' to $indexes in constructor

- $user = $cacheService->remember(fn() => [...], findBy: 'email', findValue: '...');
+ $user = $cacheService->remember(fn() => [...], findBy: 'id', findValue: 42);
```

## Tests

- 18 unit tests with Mockery assertions (memory safety, pipeline atomicity, relation hydration, SCAN safety)
- 1 feature test (container resolution)
- Pint PSR‑12 enforcement
- PHPStan static analysis (PHP 8.4)

## Chores

- Dropped Laravel 11.x / PHP 8.2-8.3 support
- Added `*.bak`, `*.backup`, `graphify-out/` to `.gitignore`
- Cleaned test directory structure
